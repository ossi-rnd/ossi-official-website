<?php

namespace App\Actions\Payroll;

use App\Enums\AuditArea;
use App\Models\EmployeeDeduction;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Safe for history: generated payslips keep their own copy of each loan line.
 */
final class DeleteEmployeeDeduction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(EmployeeDeduction $deduction, User $actor): void
    {
        DB::transaction(function () use ($deduction, $actor) {
            $this->audit->record(AuditArea::Payroll, 'deduction.deleted', "Deleted deduction \"{$deduction->label}\"", $deduction, [
                'old' => $deduction->only(['label', 'installment_amount', 'remaining_balance']),
            ], causer: $actor);

            UpdateNotice::tell($deduction->loadMissing('user')->user, $actor, 'Deduction removed: '.$deduction->label,
                'It no longer comes off your payslips. Payslips already made keep it as it was.', route('loans'), 'clipboard');

            $deduction->delete();
        });
    }
}
