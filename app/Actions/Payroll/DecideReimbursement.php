<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\ReimbursementStatus;
use App\Models\Reimbursement;
use App\Models\User;
use App\Notifications\ReimbursementNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The President or Vice President approves a reimbursement (it's paid on the next payslip) or
 * rejects it with a reason. The employee is told; Accounting too, once approved.
 * Never your own.
 */
final class DecideReimbursement
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $approver, Reimbursement $reimbursement, bool $approve, ?string $note): Reimbursement
    {
        // Never your own, nor one you filed for a guard.
        if (! $approver->can(Ability::ApproveReimbursements->value) || in_array($approver->id, [$reimbursement->user_id, $reimbursement->filed_by], true)) {
            throw new AuthorizationException;
        }

        if (! $approve && mb_strlen(trim((string) $note)) < 5) {
            throw ValidationException::withMessages(['decisionNote' => 'Say why it\'s rejected (a few words).']);
        }

        return DB::transaction(function () use ($approver, $reimbursement, $approve, $note) {
            $locked = Reimbursement::query()->with('user')->lockForUpdate()->findOrFail($reimbursement->id);

            if ($locked->status !== ReimbursementStatus::Pending) {
                throw ValidationException::withMessages(['decisionNote' => 'It was already decided, or cancelled.']);
            }

            $locked->forceFill([
                'status' => $approve ? ReimbursementStatus::Approved : ReimbursementStatus::Rejected,
                'decided_by' => $approver->id,
                'decided_at' => CarbonImmutable::now(),
                'decision_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ])->save();

            $this->audit->record(AuditArea::Payroll, $approve ? 'reimbursement.approved' : 'reimbursement.rejected', ($approve ? 'Approved' : 'Rejected')." {$locked->user->name}'s reimbursement of ₱".number_format((float) $locked->amount, 2), $locked, ['note' => $locked->decision_note], causer: $approver);

            $locked->user->notify(new ReimbursementNotice($locked, ReimbursementNotice::DECIDED));
            // Operations, for a deployment claim they filed for a guard.
            if ($locked->filedForThem()) {
                $locked->loadMissing('filer');
                $locked->filer?->notify(new ReimbursementNotice($locked, ReimbursementNotice::DECIDED));
            }
            if ($approve) {
                Notification::send(User::query()->notifiable(Ability::ManagePayroll->roles())->get(), new ReimbursementNotice($locked, ReimbursementNotice::TO_PAY));
            }

            return $locked;
        });
    }
}
