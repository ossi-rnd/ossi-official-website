<?php

namespace App\Actions\Load;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\DeductionType;
use App\Enums\LoadRequestStatus;
use App\Models\EmployeeDeduction;
use App\Models\LoadRequest;
use App\Models\User;
use App\Notifications\LoadRequestNotice;
use App\Services\AuditLogger;
use App\Services\Files\PrivateUploadStore;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Admin's side, after sending the load from the company phone:
 *
 *  - Approved: with a screenshot of the load confirmation as proof (the
 *    employee's receipt, emailed to them). In the same transaction a "Load" deduction for the full amount
 *    is created, so the next generated payslip deducts it (and it switches
 *    itself off once paid, like any balance-tracked loan);
 *  - Rejected: with a reason, nothing is deducted.
 *
 * The row is locked and the move checked against LoadRequestStatus, so two
 * Admins clicking at once can't both approve (and charge twice). Nobody
 * approves their own request.
 */
final class ReviewLoadRequest
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PrivateUploadStore $uploads,
    ) {}

    public function handle(LoadRequest $loadRequest, LoadRequestStatus $to, ?string $reason, User $actor, ?UploadedFile $proof = null): LoadRequest
    {
        if (! $actor->can(Ability::ManageLoadRequests->value) || ! in_array($to, [LoadRequestStatus::Approved, LoadRequestStatus::Rejected], true)) {
            throw new AuthorizationException;
        }

        if ($loadRequest->user_id === $actor->id) {
            throw ValidationException::withMessages(['reason' => 'You can\'t approve or reject your own load request. Another Admin has to.']);
        }

        if ($to === LoadRequestStatus::Rejected && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Say why the request is rejected.']);
        }

        if ($to === LoadRequestStatus::Approved && $proof === null) {
            throw ValidationException::withMessages(['proof' => 'Attach a screenshot of the load confirmation: it\'s the employee\'s receipt.']);
        }

        $disk = (string) config('load.disk');
        $stored = $proof !== null ? $this->uploads->store($proof, $disk, 'load-proofs/'.$loadRequest->user_id, 'load-'.$loadRequest->id, ['png', 'jpg'], 'proof') : null;

        try {
            return $this->decide($loadRequest, $to, $reason, $actor, $stored);
        } catch (\Throwable $exception) {
            // Nothing was approved: don't keep a receipt for it.
            $this->uploads->delete($disk, $stored['path'] ?? null);

            throw $exception;
        }
    }

    /**
     * @param  array{path: string, name: string, mime: string, size: int}|null  $stored
     */
    private function decide(LoadRequest $loadRequest, LoadRequestStatus $to, ?string $reason, User $actor, ?array $stored): LoadRequest
    {
        return DB::transaction(function () use ($loadRequest, $to, $reason, $actor, $stored) {
            $locked = LoadRequest::query()->whereKey($loadRequest->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canMoveTo($to)) {
                throw ValidationException::withMessages(['reason' => "Not possible: this request is \"{$locked->status->label()}\". Refresh to see its latest state."]);
            }

            $now = CarbonImmutable::now();
            $from = $locked->status;

            if ($to === LoadRequestStatus::Approved) {
                $deduction = new EmployeeDeduction([
                    'label' => $locked->deductionLabel(),
                    'type' => DeductionType::Load,
                    'installment_amount' => $locked->amount,
                    'original_amount' => $locked->amount,
                ]);
                $deduction->user()->associate($locked->user_id);
                $deduction->remaining_balance = (string) $locked->amount;
                $deduction->save();

                $locked->forceFill(['status' => $to, 'approved_by' => $actor->id, 'approved_at' => $now, 'employee_deduction_id' => $deduction->id, 'proof_path' => $stored['path'] ?? null, 'proof_mime' => $stored['mime'] ?? null])->save();
            } else {
                $locked->forceFill(['status' => $to, 'rejected_by' => $actor->id, 'rejected_at' => $now, 'rejection_reason' => $reason])->save();
            }

            $this->audit->record(AuditArea::Load, 'load.'.$to->value, "{$to->label()}: ₱{$locked->amount} {$locked->networkLabel()} load".($to === LoadRequestStatus::Approved ? ', deducted on the next payslip' : ''), $locked, [
                'old' => ['status' => $from->value],
                'new' => ['status' => $to->value],
                'reason' => $reason,
                'employee_deduction_id' => $locked->employee_deduction_id,
            ], causer: $actor);

            $locked->loadMissing('user')->user->notify(new LoadRequestNotice($locked, $to === LoadRequestStatus::Approved ? LoadRequestNotice::APPROVED : LoadRequestNotice::REJECTED));

            return $locked;
        });
    }
}
