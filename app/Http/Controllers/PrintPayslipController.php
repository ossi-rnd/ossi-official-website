<?php

namespace App\Http\Controllers;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\PayrollRunStatus;
use App\Models\PayrollRun;
use App\Models\PayslipRecord;
use App\Models\User;
use App\Queries\Payroll\ResolvePayslips;
use App\Services\AuditLogger;
use App\Support\PayslipVerification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The official, printable payslip: for the employee themselves, or payroll (manage-payroll).
 * The on-screen payslip blanks itself when printed (x-sensitive); this page is the one meant for
 * paper, so every print is audited instead. A generated payslip carries a QR code and a short code
 * anyone can check on the verification page; an estimate is marked "not an official payslip".
 */
final class PrintPayslipController extends Controller
{
    public function __invoke(Request $request, PayrollRun $run, User $user, ResolvePayslips $payslips, AuditLogger $audit): View
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $own = $viewer->is($user);

        abort_unless($own || $viewer->can(Ability::ManagePayroll->value), 403);
        // An employee sees a run only once it's past Draft, as on My Payslips.
        abort_if($own && ! $viewer->can(Ability::ManagePayroll->value) && $run->status === PayrollRunStatus::Draft, 404);

        $user->loadMissing('employeeProfile');
        $breakdown = $payslips->forUser($run, $user);
        // No attendance and nothing generated: there is no payslip to print (as on screen).
        abort_if(! $breakdown->isGenerated && $breakdown->daysWorked === 0, 404);
        $record = PayslipRecord::query()->where('payroll_run_id', $run->id)->where('user_id', $user->id)->first();

        $audit->record(AuditArea::Payroll, 'payslip.printed', ($own ? 'Printed their own payslip' : "Printed {$user->name}'s payslip")." for {$run->periodLabel()}", $record ?? $run, causer: $viewer);

        return view('payslips.print', [
            'run' => $run,
            'employee' => $user,
            'payslip' => $breakdown,
            'record' => $record,
            'code' => $record ? PayslipVerification::code($record) : null,
            'verifyUrl' => $record ? PayslipVerification::url($record) : null,
            'qr' => $record ? PayslipVerification::qrSvg($record) : null,
            'printedBy' => $viewer,
        ]);
    }
}
