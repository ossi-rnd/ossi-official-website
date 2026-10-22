<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\ReimbursementCategory;
use App\Models\AbsenceNotice;
use App\Models\AwayLog;
use App\Models\Reimbursement;
use App\Models\User;
use App\Notifications\ReimbursementNotice;
use App\Services\AuditLogger;
use App\Services\Files\PrivateUploadStore;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Office staff file what they spent for the company (roadmap 25): what for, the
 * date, the amount, the purpose and the receipt (always). It can point to the
 * Office Away trip it came from. The President or Vice President is told.
 *
 * Deployment claims (roadmap 5): Operations ($filedBy) files one for a guard who paid
 * for a deployment; the guard ($employee) is paid back on their payslip and is told.
 * A "Deployment" cost always says which deployment ($deploymentFor). A reliever's fare
 * can come from the relief itself ($relief): prefilled, linked, one standing claim per relief.
 */
final class FileReimbursement
{
    public function __construct(
        private readonly PrivateUploadStore $uploads,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * $acknowledged: the employee ticked "I certify these are true" on the form; the lines
     * (Reimbursement::acknowledgementLines) are saved word for word, with the time.
     */
    public function handle(User $employee, ReimbursementCategory $category, CarbonImmutable $spentOn, float $amount, string $purpose, UploadedFile $receipt, ?int $awayLogId = null, bool $acknowledged = false, string $locale = 'en', ?User $filedBy = null, ?string $deploymentFor = null, ?AbsenceNotice $relief = null): Reimbursement
    {
        if ($filedBy !== null && $filedBy->id !== $employee->id) {
            if (! Reimbursement::canFileFor($filedBy, $employee)) {
                throw new AuthorizationException;
            }

            // A reliever's fare, from the relief: it must be theirs, done, and in the receipt window.
            if ($relief !== null && ($relief->reliever_id !== $employee->id || ! $relief->fareClaimable())) {
                throw ValidationException::withMessages(['relief' => 'That relief isn\'t this guard\'s, or isn\'t done yet.']);
            }

            // A guard's claim is always a deployment cost: the relief, or their post, unless said otherwise.
            $category = ReimbursementCategory::Deployment;
            $awayLogId = null;
            $deploymentFor = filled($deploymentFor) ? $deploymentFor : ($relief?->reliefLabel() ?? $employee->employeeProfile?->assignmentLabel());
        } else {
            $relief = null;
            $filedBy = null;

            if (! Reimbursement::canFile($employee)) {
                throw new AuthorizationException;
            }
        }

        if ($category === ReimbursementCategory::Deployment && blank($deploymentFor)) {
            throw ValidationException::withMessages(['deploymentFor' => 'Say which deployment: the post, and who was relieved or moved.']);
        }

        $today = CarbonImmutable::today();
        $cap = Reimbursement::maxAmount(deployment: $filedBy !== null);
        $errors = array_filter([
            'spentOn' => match (true) {
                $spentOn->isAfter($today) => 'The date can\'t be in the future.',
                $spentOn->isBefore($today->subDays(Reimbursement::fileWithinDays())) => 'Only receipts from the last '.Reimbursement::fileWithinDays().' days.',
                default => null,
            },
            // A guard's deployment claim has its own cap (Reimbursement::maxAmount(deployment: true)).
            'amount' => $amount <= 0 || $amount > $cap ? 'From ₱1 to ₱'.number_format($cap).'. Bigger purchases go through Accounting.' : null,
            'purpose' => mb_strlen(trim($purpose)) < 10 ? 'Say what it was for, and for whom.' : null,
            'awayLogId' => $awayLogId !== null && ! AwayLog::query()->whereKey($awayLogId)->where('user_id', $employee->id)->exists() ? 'Choose one of your own trips.' : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $disk = (string) config('payroll.concerns.disk');
        $stored = $this->uploads->store($receipt, $disk, 'reimbursements/'.$employee->id, 'receipt', ['png', 'jpg', 'pdf'], 'receipt');

        try {
            return DB::transaction(function () use ($employee, $category, $spentOn, $amount, $purpose, $awayLogId, $stored, $acknowledged, $locale, $filedBy, $deploymentFor, $relief) {
                // One standing fare claim per relief: the relief row is locked so two staff can't both claim it.
                if ($relief !== null) {
                    $locked = AbsenceNotice::query()->with('fareClaims')->lockForUpdate()->findOrFail($relief->id);

                    if (($standing = $locked->activeFareClaim()) !== null) {
                        throw ValidationException::withMessages(['relief' => 'This relief\'s fare is already claimed (₱'.number_format((float) $standing->amount, 2).', '.strtolower($standing->status->label()).').']);
                    }
                }

                $reimbursement = new Reimbursement;
                $reimbursement->forceFill([
                    'user_id' => $employee->id,
                    'filed_by' => $filedBy?->id,
                    'category' => $category,
                    'spent_on' => $spentOn->toDateString(),
                    'amount' => number_format($amount, 2, '.', ''),
                    'purpose' => trim($purpose),
                    'deployment_for' => $category === ReimbursementCategory::Deployment ? trim((string) $deploymentFor) : null,
                    'absence_notice_id' => $relief?->id,
                    'away_log_id' => $awayLogId,
                    'receipt_path' => $stored['path'],
                    'receipt_name' => $stored['name'],
                    'receipt_mime' => $stored['mime'],
                    'acknowledgement' => $acknowledged ? Reimbursement::acknowledgementLines($locale, forGuard: $filedBy !== null) : null,
                    'acknowledged_at' => $acknowledged ? now() : null,
                ])->save();

                $this->audit->record(AuditArea::Payroll, 'reimbursement.filed', $filedBy !== null
                    ? "{$filedBy->name} filed a deployment claim for {$employee->name}: ₱".number_format($amount, 2)
                    : "{$employee->name} filed a reimbursement: {$category->short()} ₱".number_format($amount, 2), $reimbursement, causer: $filedBy ?? $employee);

                Notification::send(User::query()->notifiable(Ability::ApproveReimbursements->roles())->whereKeyNot(array_filter([$employee->id, $filedBy?->id]))->get(), new ReimbursementNotice($reimbursement, ReimbursementNotice::FILED));

                // The guard hears that Operations filed it for them, and that it's coming on their payslip.
                if ($filedBy !== null) {
                    $employee->notify(new ReimbursementNotice($reimbursement->setRelation('filer', $filedBy), ReimbursementNotice::FILED_FOR_YOU));
                }

                return $reimbursement;
            });
        } catch (\Throwable $e) {
            $this->uploads->delete($disk, $stored['path']);

            throw $e;
        }
    }
}
