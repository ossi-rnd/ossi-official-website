<?php

use App\Actions\Payroll\GeneratePayslip;
use App\Enums\Role;
use App\Enums\ThirteenthMonthStatus;
use App\Livewire\Admin\ThirteenthMonth;
use App\Livewire\Payroll\MyPayslips;
use App\Models\ActivityLog;
use App\Models\PayrollRun;
use App\Models\ThirteenthMonthPayout;
use App\Models\ThirteenthMonthRun;
use App\Models\TimeLog;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-18 09:00'));
    $this->accounting = staff(Role::Accounting);
    $this->guard = employee();
});

/** Generates a payslip for $user in a new run, with one punch on each given date. */
function workedRun(string $start, string $end, $user, array $dates, $actor): PayrollRun
{
    $run = payrollRun($start, $end);
    foreach ($dates as $date) {
        TimeLog::factory()->on(CarbonImmutable::parse($date))->create(['user_id' => $user->id]);
    }
    app(GeneratePayslip::class)->handle($run, $user, $actor);

    return $run;
}

it('pays 1/12 of the basic pay in the year\'s generated payslips, then employees see it once approved', function () {
    // ₱610 × (5 + 3) days = ₱4,880 basic → ₱406.67. Last year's payslip doesn't count.
    workedRun('2025-12-01', '2025-12-15', $this->guard, ['2025-12-02'], $this->accounting);
    workedRun('2026-01-01', '2026-01-15', $this->guard, ['2026-01-05', '2026-01-06', '2026-01-07', '2026-01-08', '2026-01-09'], $this->accounting);
    workedRun('2026-12-01', '2026-12-15', $this->guard, ['2026-12-01', '2026-12-02', '2026-12-03'], $this->accounting);

    $page = Livewire::actingAs($this->accounting)->test(ThirteenthMonth::class)
        ->call('startCreate')
        ->assertSet('coversUntil', '2026-12-15')      // the last generated cutoff
        ->assertSet('payDate', '2026-12-20')
        ->call('createRun')
        ->assertHasNoErrors()
        ->assertSee('₱406.67');

    $payout = ThirteenthMonthPayout::query()->sole();
    expect($payout->days_worked)->toBe(8)
        ->and($payout->basic_pay)->toBe('4880.00')
        ->and($payout->amount)->toBe('406.67')
        ->and($payout->cutoffs)->toHaveCount(2);

    // Not visible to the employee while it's a draft.
    Livewire::actingAs($this->guard)->test(MyPayslips::class)->assertDontSee('13th month pay');

    $this->actingAs($this->accounting);   // the employee check above switched the session user
    $page->call('advance')->assertHasNoErrors();
    expect(ThirteenthMonthRun::query()->sole()->status)->toBe(ThirteenthMonthStatus::Approved);

    Livewire::actingAs($this->guard)->test(MyPayslips::class)->assertSee('13th month pay')->assertSee('₱406.67');

    $this->actingAs($this->accounting);
    $page->call('advance');
    expect($payout->fresh()->paid_at)->not->toBeNull()
        ->and(ActivityLog::query()->whereIn('event', ['thirteenth_month.created', 'thirteenth_month.approved', 'thirteenth_month.paid'])->count())->toBe(3);
});

it('pays only the balance in a later run for the same year, and keeps runs from overlapping', function () {
    workedRun('2026-12-01', '2026-12-15', $this->guard, ['2026-12-01', '2026-12-02'], $this->accounting);   // ₱1,220 → ₱101.67

    $page = Livewire::actingAs($this->accounting)->test(ThirteenthMonth::class)
        ->call('startCreate')->call('createRun')->call('advance');

    // A run covering no new days is refused.
    $page->call('startCreate')->set('coversUntil', '2026-12-15')->call('createRun')->assertHasErrors(['coversUntil']);

    // December's last cutoff, generated after the payout: ₱610 × 4 more days.
    $this->travelTo(CarbonImmutable::parse('2027-01-02 09:00'));
    workedRun('2026-12-16', '2026-12-31', $this->guard, ['2026-12-16', '2026-12-17', '2026-12-18', '2026-12-19'], $this->accounting);

    $page->call('startCreate')->set('year', '2026')->set('coversUntil', '2026-12-31')->set('payDate', '2027-01-10')->call('createRun')->assertHasNoErrors()
        ->assertSee('after December 24');

    // (₱1,220 + ₱2,440) ÷ 12 = ₱305.00 earned; ₱101.67 already paid → ₱203.33.
    $balance = ThirteenthMonthRun::query()->latest('id')->first()->payouts()->sole();
    expect($balance->earned)->toBe('305.00')
        ->and($balance->earlier_paid)->toBe('101.67')
        ->and($balance->amount)->toBe('203.33');

    $page->call('startCreate')->set('year', '2026')->set('coversUntil', '2026-12-31')->call('createRun')->assertHasErrors(['year']);   // one draft at a time
});

it('refuses to approve numbers that changed since they were computed, and refreshes them', function () {
    workedRun('2026-12-01', '2026-12-15', $this->guard, ['2026-12-01'], $this->accounting);
    $page = Livewire::actingAs($this->accounting)->test(ThirteenthMonth::class)->call('startCreate')->call('createRun');

    // A colleague's payslip for the same period is generated after the compute.
    $other = employee();
    TimeLog::factory()->on(CarbonImmutable::parse('2026-12-02'))->create(['user_id' => $other->id]);
    app(GeneratePayslip::class)->handle(PayrollRun::query()->sole(), $other, $this->accounting);

    $page->call('advance')->assertHasErrors(['run']);

    $run = ThirteenthMonthRun::query()->sole();
    expect($run->status)->toBe(ThirteenthMonthStatus::Draft)
        ->and($run->payouts()->count())->toBe(2);    // refreshed, kept

    $page->call('advance')->assertHasNoErrors();
    expect($run->fresh()->status)->toBe(ThirteenthMonthStatus::Approved);
});

it('deletes drafts only, and keeps the page to payroll managers', function () {
    workedRun('2026-12-01', '2026-12-15', $this->guard, ['2026-12-01'], $this->accounting);
    $page = Livewire::actingAs($this->accounting)->test(ThirteenthMonth::class)->call('startCreate')->call('createRun');

    $page->call('deleteRun')->assertHasNoErrors();
    expect(ThirteenthMonthRun::query()->count())->toBe(0)->and(ThirteenthMonthPayout::query()->count())->toBe(0);

    $page->call('startCreate')->call('createRun')->call('advance');
    $page->call('deleteRun')->assertHasErrors(['run']);

    $this->actingAs(staff(Role::Hr))->get(route('thirteenth-month'))->assertForbidden();
    $this->actingAs($this->accounting)->get(route('thirteenth-month'))->assertOk();
});
