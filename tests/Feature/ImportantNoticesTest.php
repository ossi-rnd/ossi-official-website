<?php

use App\Actions\Leave\ReviewLeaveRequest;
use App\Actions\Overtime\ReviewOvertimeRequest;
use App\Actions\Payroll\AdvancePayrollRun;
use App\Actions\Payroll\GeneratePayslip;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\TimeLog;
use App\Notifications\PayslipReady;
use App\Notifications\RequestDecided;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

// Decisions about someone reach them in the bell: their leave and overtime
// answers, and their payslip once it's approved and once it's paid.

it('tells the employee their leave was approved, with the reviewer\'s note', function () {
    Notification::fake();
    $clerk = staff(Role::Accounting);
    // An accountant's head, who decides their leave (App\Support\Heads).
    $hr = staff(Role::Hr);
    $leave = new LeaveRequest(['type' => 'vacation', 'start_date' => today()->addDays(3), 'end_date' => today()->addDays(4), 'reason' => 'Family trip']);
    $leave->user()->associate($clerk);
    $leave->save();

    app(ReviewLeaveRequest::class)->handle($leave, $hr, RequestStatus::Approved, 'Enjoy, file the turnover first.');

    Notification::assertSentTo($clerk, RequestDecided::class, function (RequestDecided $notice) use ($clerk, $hr) {
        $data = $notice->toArray($clerk);

        return str_starts_with($data['title'], 'Leave approved: ')
            && $data['body'] === "{$hr->name} approved it. Note: Enjoy, file the turnover first."
            && $data['url'] === route('leave')
            && $notice->via($clerk) === ['database', 'mail'];
    });
});

it('tells the employee when less overtime was approved than they asked for, or none', function () {
    Notification::fake();
    $guard = employee();
    $hr = staff(Role::Hr);
    $make = function (int $minutes) use ($guard, $hr) {
        $overtime = new OvertimeRequest(['date' => '2026-10-08', 'requested_minutes' => $minutes, 'reason' => 'Relief was late']);
        $overtime->user()->associate($guard);
        $overtime->supervisor()->associate($hr);
        $overtime->save();

        return $overtime;
    };

    app(ReviewOvertimeRequest::class)->handle($make(180), $hr, RequestStatus::Approved, null, 120);
    app(ReviewOvertimeRequest::class)->handle($make(60), $hr, RequestStatus::Rejected, 'Not pre-approved.');

    $bodies = [];
    Notification::assertSentTo($guard, RequestDecided::class, function (RequestDecided $notice) use ($guard, &$bodies) {
        $bodies[] = $notice->toArray($guard)['title'].' | '.$notice->toArray($guard)['body'];

        return true;
    });

    expect($bodies)->toContain("Overtime approved: Oct 8 | {$hr->name} approved 2 hours (you asked for 3 hours).")
        ->toContain("Overtime not approved: Oct 8 | {$hr->name} did not approve it. Note: Not pre-approved.");
});

it('tells everyone in a payroll run when their payslip is ready, and again when it\'s paid', function () {
    $accounting = staff(Role::Accounting);
    $guard = employee();
    $run = payrollRun('2026-09-01', '2026-09-15');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-09-02'))->create(['user_id' => $guard->id]);
    app(GeneratePayslip::class)->handle($run, $guard, $accounting);
    $notInRun = employee();

    Notification::fake();
    app(AdvancePayrollRun::class)->handle($run, $accounting); // → approved
    Notification::assertSentTo($guard, PayslipReady::class, fn (PayslipReady $notice) => ! $notice->paid
        && $notice->toArray($guard)['title'] === 'Payslip ready: '.$run->periodLabel()
        && str_ends_with($notice->toArray($guard)['body'], (string) config('payroll.disclaimer')) // read before the figures
        && $notice->via($guard) === ['database']);
    Notification::assertNotSentTo($notInRun, PayslipReady::class);

    app(AdvancePayrollRun::class)->handle($run->fresh() ?? $run, $accounting); // → paid
    Notification::assertSentTo($guard, PayslipReady::class, fn (PayslipReady $notice) => $notice->paid
        && $notice->toArray($guard)['title'] === 'Pay released: '.$run->periodLabel());
});
