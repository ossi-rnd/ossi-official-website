<?php

namespace App\Actions\Payroll;

use App\Enums\AuditArea;
use App\Enums\PayrollRunStatus;
use App\Models\PayrollRun;
use App\Models\User;
use App\Notifications\PayslipReady;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * draft → processing → approved → paid.
 *
 *  - approving waits until the period has ended (attendance, leave and
 *    overtime can still change during it) and requires every employee who
 *    worked in the period to have a generated payslip, so nobody sees
 *    "Approved" next to an estimate;
 *  - marking paid stamps paid_at / paid_by on every payslip of the run.
 */
final class AdvancePayrollRun
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DetectPriorPeriodCorrections $corrections,
        private readonly DetectPaidLeave $paidLeave,
    ) {}

    public function handle(PayrollRun $run, User $actor): PayrollRun
    {
        return DB::transaction(function () use ($run, $actor) {
            $locked = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);
            $from = $locked->status;
            $to = $from->next();

            if ($to === null) {
                throw ValidationException::withMessages(['run' => 'This run is already paid.']);
            }

            if ($to === PayrollRunStatus::Approved && ($reason = $this->approvalBlockedReason($locked)) !== null) {
                throw ValidationException::withMessages(['run' => $reason]);
            }

            $locked->status = $to;
            $locked->save();

            if ($to === PayrollRunStatus::Paid) {
                $locked->payslipRecords()->whereNull('paid_at')->update(['paid_at' => now(), 'paid_by' => $actor->id]);
            }

            // Processing: list pay that changed on earlier cut-offs straight away, for Accounting to decide.
            if ($to === PayrollRunStatus::Processing) {
                $this->corrections->handle($locked);
                // And the leave the law pays in this cut-off.
                $this->paidLeave->handle($locked);
            }

            $this->audit->record(AuditArea::Payroll, $to === PayrollRunStatus::Paid ? 'run.paid' : 'run.'.$to->value, "Moved payroll run {$locked->periodLabel()} to {$to->label()}", $locked, [
                'old' => ['status' => $from->value],
                'new' => ['status' => $to->value],
            ], causer: $actor);

            // Everyone with a payslip in it: "ready" once approved, "released" once paid.
            if ($to === PayrollRunStatus::Approved || $to === PayrollRunStatus::Paid) {
                $employees = User::query()->whereHas('payslipRecords', fn ($payslip) => $payslip->where('payroll_run_id', $locked->id))->get();
                Notification::send($employees, new PayslipReady($locked, paid: $to === PayrollRunStatus::Paid));
            }

            return $locked;
        });
    }

    /**
     * Why the run can't be approved yet, or null when it can. The run page
     * shows the same text as the locked button's tooltip.
     */
    public function approvalBlockedReason(PayrollRun $run): ?string
    {
        // Not while "Generate all" is still building payslips in the background.
        if ($run->isGenerating()) {
            return 'Payslips are still being generated. Approve once that finishes.';
        }

        if (! today()->isAfter($run->end_date)) {
            return 'Available from '.$run->end_date->copy()->addDay()->format('M j, Y').'. The period runs until '.$run->end_date->format('M j').', so attendance, leave and overtime can still change. Generate payslips now to check the numbers.';
        }

        $missing = $this->employeesWithoutPayslip($run);

        return $missing > 0
            ? $missing.' '.str('employee')->plural($missing).' who worked this period '.($missing === 1 ? 'has' : 'have').' no payslip yet. Click “Generate all” (or Generate on each row) first.'
            : null;
    }

    public function employeesWithoutPayslip(PayrollRun $run): int
    {
        return User::query()
            ->onPayroll()
            ->dueAPayslip($run)
            ->whereDoesntHave('payslipRecords', fn ($query) => $query->where('payroll_run_id', $run->id))
            ->count();
    }
}
