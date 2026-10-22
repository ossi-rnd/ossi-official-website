<?php

use App\Actions\Payroll\DetectPriorPeriodCorrections;
use App\Actions\Payroll\GeneratePayslip;
use App\Actions\People\CancelPositionChange;
use App\Actions\People\ChangePosition;
use App\Actions\People\SaveEmployeeProfile;
use App\Enums\Position;
use App\Enums\Role;
use App\Models\EmployeeRate;
use App\Models\PositionChange;
use App\Models\PositionRate;
use App\Models\PriorPeriodCorrection;
use App\Models\TimeLog;
use App\Notifications\PositionChangeNotice;
use App\Queries\People\EffectiveRatesQuery;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

// Promotions (roadmap 22): position history, so pay uses the position held on
// each cut-off's start. A promotion pays from its date only; a back-dated one is
// suggested as corrections for the cut-offs already paid.

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00'));

    foreach ([[Position::SecurityGuard, 610], [Position::ShiftInCharge, 800]] as [$position, $rate]) {
        (new PositionRate)->forceFill(['position' => $position, 'daily_rate' => $rate, 'effective_from' => '2025-01-01', 'note' => 'Test'])->save();
    }

    $this->hr = staff(Role::Hr);
    $this->accounting = staff(Role::Accounting);
    $this->guard = employee(['position' => Position::SecurityGuard]);
    $this->profile = $this->guard->employeeProfile;
});

function rateOn(object $test, string $date): float
{
    return app(EffectiveRatesQuery::class)->forProfile($test->profile->fresh(), CarbonImmutable::parse($date))->rate;
}

it('promotes from a cut-off start ahead: the old rate until then, the new one from it, on the record on its day', function () {
    $change = app(ChangePosition::class)->handle($this->hr, $this->profile, Position::ShiftInCharge, PositionChange::PROMOTION, CarbonImmutable::parse('2026-11-01'), 'Passed the shift-in-charge assessment.');

    expect($change->applied_at)->toBeNull()
        ->and($this->profile->fresh()?->position)->toBe(Position::SecurityGuard)
        ->and(rateOn($this, '2026-10-16'))->toBe(610.0)
        ->and(rateOn($this, '2026-11-01'))->toBe(800.0);

    Notification::assertSentTo($this->guard, PositionChangeNotice::class, fn (PositionChangeNotice $n) => $n->toArray($this->guard)['title'] === 'Congratulations: promoted to Shift-in-Charge');
    Notification::assertSentTo($this->accounting, PositionChangeNotice::class, fn (PositionChangeNotice $n) => str_contains($n->toArray($this->accounting)['body'], '₱800.00'));

    // Only one waiting at a time; it can be cancelled before its day.
    expect(fn () => app(ChangePosition::class)->handle($this->hr, $this->profile, Position::DetachmentCommander, PositionChange::PROMOTION, CarbonImmutable::parse('2026-12-01'), 'Another promotion.'))
        ->toThrow(ValidationException::class, 'already waiting');

    // Its day: the record takes it.
    $this->travelTo(CarbonImmutable::parse('2026-11-01 00:05'));
    $this->artisan('app:apply-position-changes')->expectsOutput('Applied 1 change of position.');
    expect($this->profile->fresh()?->position)->toBe(Position::ShiftInCharge)->and($change->fresh()?->applied_at)->not->toBeNull();
});

it('suggests the raise for cut-offs already paid when it\'s back-dated', function () {
    $paid = payrollRun('2026-10-01', '2026-10-15');
    foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'] as $date) {
        TimeLog::factory()->on(CarbonImmutable::parse($date))->create(['user_id' => $this->guard->id]);
    }
    app(GeneratePayslip::class)->handle($paid, $this->guard, $this->accounting);
    $paid->forceFill(['status' => 'approved'])->save();

    $open = payrollRun('2026-10-16', '2026-10-31');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-10-16'))->create(['user_id' => $this->guard->id]);

    // Approved today, effective Oct 1.
    app(ChangePosition::class)->handle($this->hr, $this->profile, Position::ShiftInCharge, PositionChange::PROMOTION, CarbonImmutable::parse('2026-10-01'), 'Promotion approved by Operations, from Oct 1.');
    expect($this->profile->fresh()?->position)->toBe(Position::ShiftInCharge);

    app(DetectPriorPeriodCorrections::class)->handle($open);
    $correction = PriorPeriodCorrection::query()->sole();
    expect((float) $correction->amount)->toBe(950.0) // 5 days × (₱800 − ₱610)
        ->and($correction->summary())->toBe('daily rate ₱610.00 → ₱800.00');
});

it('keeps a position edited on the form in the history, so earlier cut-offs keep their rate', function () {
    app(SaveEmployeeProfile::class)->handle($this->profile, ['position' => Position::ShiftInCharge], null, $this->hr);

    expect(PositionChange::query()->sole())->kind->toBe(PositionChange::EDIT)->from_position->toBe(Position::SecurityGuard)
        ->and(rateOn($this, '2026-10-01'))->toBe(610.0)
        ->and(rateOn($this, '2026-10-16'))->toBe(800.0);
});

it('needs a cut-off start, a position for their staff type, and a reason; HR only', function () {
    $change = app(ChangePosition::class);

    expect(fn () => $change->handle($this->hr, $this->profile, Position::ShiftInCharge, PositionChange::PROMOTION, CarbonImmutable::parse('2026-11-05'), 'Promotion.'))->toThrow(ValidationException::class, 'the 1st or the 16th');
    expect(fn () => $change->handle($this->hr, $this->profile, Position::AdminStaff, PositionChange::TRANSFER, CarbonImmutable::parse('2026-11-01'), 'Moved to the office.'))->toThrow(ValidationException::class, 'field staff');
    expect(fn () => $change->handle($this->hr, $this->profile, Position::SecurityGuard, PositionChange::TRANSFER, CarbonImmutable::parse('2026-11-01'), 'Same.'))->toThrow(ValidationException::class, 'already their position');
    expect(fn () => $change->handle($this->hr, $this->profile, Position::ShiftInCharge, PositionChange::PROMOTION, CarbonImmutable::parse('2026-11-01'), ''))->toThrow(ValidationException::class, 'Say why');
    expect(fn () => $change->handle($this->accounting, $this->profile, Position::ShiftInCharge, PositionChange::PROMOTION, CarbonImmutable::parse('2026-11-01'), 'Promotion.'))->toThrow(AuthorizationException::class);
});

it('tells Accounting when a personal rate still wins over the new position\'s', function () {
    (new EmployeeRate)->forceFill(['employee_profile_id' => $this->profile->id, 'daily_rate' => 700, 'effective_from' => '2026-01-01', 'note' => 'Agreed rate', 'set_by' => $this->accounting->id])->save();

    $recorded = app(ChangePosition::class)->handle($this->hr, $this->profile, Position::ShiftInCharge, PositionChange::PROMOTION, CarbonImmutable::parse('2026-11-01'), 'Passed the assessment.');

    Notification::assertSentTo($this->accounting, PositionChangeNotice::class, fn (PositionChangeNotice $n) => str_contains($n->toArray($this->accounting)['body'], 'personal rate'));

    app(CancelPositionChange::class)->handle($this->hr, $recorded);
    expect(PositionChange::query()->count())->toBe(0);
});
