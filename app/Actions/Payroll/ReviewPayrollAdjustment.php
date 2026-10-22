<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AdjustmentStatus;
use App\Enums\AuditArea;
use App\Models\PayrollAdjustment;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The second person's decision on a large adjustment. The creator can never
 * approve their own: that is the whole point of a second person.
 */
final class ReviewPayrollAdjustment
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PayrollAdjustment $adjustment, bool $approve, ?string $note, User $actor): PayrollAdjustment
    {
        if (! $actor->can(Ability::ApprovePayrollAdjustments->value) || $adjustment->created_by === $actor->id) {
            throw new AuthorizationException('A different person must approve this adjustment.');
        }

        if (! $approve && blank($note)) {
            throw ValidationException::withMessages(['reviewNote' => 'Say why the adjustment is rejected.']);
        }

        return DB::transaction(function () use ($adjustment, $approve, $note, $actor) {
            $locked = PayrollAdjustment::query()->with(['employee:id,name', 'creator'])->lockForUpdate()->findOrFail($adjustment->id);

            if ($locked->status !== AdjustmentStatus::Pending) {
                throw ValidationException::withMessages(['reviewNote' => "This adjustment is already {$locked->status->label()}."]);
            }

            $locked->forceFill([
                'status' => $approve ? AdjustmentStatus::Approved : AdjustmentStatus::Rejected,
                'reviewed_by' => $actor->id,
                'reviewed_at' => CarbonImmutable::now(),
                'review_note' => filled($note) ? $note : null,
            ])->save();

            $this->audit->record(AuditArea::Payroll, $approve ? 'adjustment.approved' : 'adjustment.rejected', ($approve ? 'Approved' : 'Rejected').' the ₱'.number_format(abs((float) $locked->amount), 2)." adjustment on {$locked->employee->name}'s payslip", $locked, [
                'old' => ['status' => AdjustmentStatus::Pending->value],
                'new' => ['status' => $locked->status->value],
                'note' => $locked->review_note,
            ], causer: $actor);

            // The one who asked for the second check hears the answer.
            UpdateNotice::tell($locked->creator, $actor, ($approve ? 'Adjustment approved: ' : 'Adjustment rejected: ').$locked->employee->name,
                '₱'.number_format(abs((float) $locked->amount), 2).($approve ? ' is on the payslip now.' : ". {$locked->review_note}"), route('payroll', ['run' => $locked->payroll_run_id]), 'banknotes');

            return $locked;
        });
    }
}
