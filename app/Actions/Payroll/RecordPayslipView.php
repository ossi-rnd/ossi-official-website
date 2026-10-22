<?php

namespace App\Actions\Payroll;

use App\Enums\AuditArea;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Who opened whose payslip, and when. A website can't stop a screenshot, but
 * a leaked payslip can be traced to the people who opened it (see the viewer
 * watermark in <x-sensitive>).
 */
final class RecordPayslipView
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PayrollRun $run, User $employee, User $viewer): void
    {
        $whose = $employee->is($viewer) ? 'their own' : "{$employee->name}'s";

        $this->audit->record(
            AuditArea::Payroll,
            'payslip.viewed',
            "Viewed {$whose} payslip for {$run->periodLabel()}",
            $run,
            ['employee_id' => $employee->id],
            causer: $viewer,
        );
    }
}
