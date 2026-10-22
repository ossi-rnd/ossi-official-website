<?php

namespace App\Actions\Payroll;

use App\Enums\AuditArea;
use App\Models\EmployeeDeduction;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Pauses or resumes a deduction without losing its balance, e.g. an employee
 * on unpaid leave for a cut-off.
 */
final class ToggleEmployeeDeduction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(EmployeeDeduction $deduction, User $actor): void
    {
        DB::transaction(function () use ($deduction, $actor) {
            $deduction->is_active = ! $deduction->is_active;
            $deduction->save();

            $this->audit->record(AuditArea::Payroll, $deduction->is_active ? 'deduction.resumed' : 'deduction.paused', ($deduction->is_active ? 'Resumed' : 'Paused')." deduction \"{$deduction->label}\"", $deduction, causer: $actor);

            UpdateNotice::tell($deduction->loadMissing('user')->user, $actor, ($deduction->is_active ? 'Deduction resumed: ' : 'Deduction paused: ').$deduction->label,
                $deduction->is_active ? 'It comes off your payslips again from the next cut-off.' : 'It won\'t come off your payslips until it\'s resumed. The balance stays.', route('loans'), 'clipboard');
        });
    }
}
