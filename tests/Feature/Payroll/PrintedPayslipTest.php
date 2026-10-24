<?php

use App\Actions\Payroll\GenerateRunPayslips;
use App\Enums\Role;
use App\Livewire\Payroll\MyPayslips;
use App\Models\ActivityLog;
use App\Models\PayslipRecord;
use App\Models\TimeLog;
use App\Support\PayslipVerification;
use Carbon\CarbonImmutable;

// The printable payslip carries a QR code signed with the app key: anyone holding the paper can
// check it against the system, and a forged or edited code finds nothing.

beforeEach(function () {
    $this->accounting = staff(Role::Accounting);
    $this->guard = employee();
    TimeLog::factory()->on(CarbonImmutable::parse('2026-09-02'))->create(['user_id' => $this->guard->id]);
    $this->run = payrollRun('2026-09-01', '2026-09-15', 'processing');
});

function generatedPayslip(): PayslipRecord
{
    $test = test();
    app(GenerateRunPayslips::class)->handle($test->run, $test->accounting);

    return PayslipRecord::query()->where('user_id', $test->guard->id)->firstOrFail();
}

it('prints a generated payslip with a QR code that verifies, and audits the print', function () {
    $record = generatedPayslip();
    $code = PayslipVerification::code($record);

    $this->actingAs($this->guard)->get(route('payslips.print', ['run' => $this->run, 'user' => $this->guard]))
        ->assertOk()->assertSee('PAYSLIP')->assertSee($code)->assertSee('<svg', false)->assertDontSee('NOT AN OFFICIAL PAYSLIP');

    expect(ActivityLog::query()->where('event', 'payslip.printed')->count())->toBe(1);

    auth()->logout();
    $this->get(PayslipVerification::url($record))
        ->assertOk()->assertSee('Verified')->assertDontSee('Not verified')->assertSee($this->guard->name)
        ->assertSee(number_format((float) $record->net_pay, 2))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('rejects a made-up code, a code for another payslip, and a payslip changed after printing', function () {
    $record = generatedPayslip();
    $code = PayslipVerification::code($record);

    $this->get(route('payslip.verify', ['payslip' => $record->id, 'code' => 'AAAA-BBBB-CCCC-DDDD']))->assertNotFound()->assertSee('Not verified');
    $this->get(route('payslip.verify', ['payslip' => $record->id + 1, 'code' => $code]))->assertNotFound();

    // Someone edits the net pay in the database: the printed code no longer matches.
    PayslipRecord::query()->whereKey($record->id)->update(['net_pay' => (float) $record->net_pay + 1000]);
    $this->get(route('payslip.verify', ['payslip' => $record->id, 'code' => $code]))->assertNotFound();
});

it('accepts the code typed in lower case', function () {
    $record = generatedPayslip();

    $this->get(route('payslip.verify', ['payslip' => $record->id, 'code' => strtolower(PayslipVerification::code($record))]))->assertOk();
});

it('marks an estimate as not official and gives it no QR code', function () {
    $this->actingAs($this->guard)->get(route('payslips.print', ['run' => $this->run, 'user' => $this->guard]))
        ->assertOk()->assertSee('NOT AN OFFICIAL PAYSLIP')->assertDontSee('<svg', false);
});

it('has nothing to print for a period without attendance', function () {
    $idle = employee();

    $this->actingAs($idle)->get(route('payslips.print', ['run' => $this->run, 'user' => $idle]))->assertNotFound();
    Livewire\Livewire::actingAs($idle)->test(MyPayslips::class)->call('viewRun', $this->run->id)
        ->assertSee('No attendance in this period')->assertDontSee(route('payslips.print', ['run' => $this->run, 'user' => $idle]));
    Livewire\Livewire::actingAs($this->guard)->test(MyPayslips::class)->call('viewRun', $this->run->id)
        ->assertSee(route('payslips.print', ['run' => $this->run, 'user' => $this->guard]));
});

it('lets payroll print anyone, but nobody else prints another person\'s payslip', function () {
    generatedPayslip();
    $url = route('payslips.print', ['run' => $this->run, 'user' => $this->guard]);

    $this->actingAs($this->accounting)->get($url)->assertOk();
    $this->actingAs(employee())->get($url)->assertForbidden();
    $this->actingAs(staff(Role::OperationsHq))->get($url)->assertForbidden();
});

it('hides a draft run from the employee', function () {
    $draft = payrollRun('2026-09-16', '2026-09-30', 'draft');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-09-17'))->create(['user_id' => $this->guard->id]);

    $this->actingAs($this->guard)->get(route('payslips.print', ['run' => $draft, 'user' => $this->guard]))->assertNotFound();
    $this->actingAs($this->accounting)->get(route('payslips.print', ['run' => $draft, 'user' => $this->guard]))->assertOk();
});
