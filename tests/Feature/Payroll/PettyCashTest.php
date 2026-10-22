<?php

use App\Actions\Finance\DecideReplenishment;
use App\Actions\Finance\DeletePettyCashExpense;
use App\Actions\Finance\RecordPettyCashExpense;
use App\Actions\Finance\RequestReplenishment;
use App\Actions\Finance\SavePettyCashFund;
use App\Enums\ReplenishmentStatus;
use App\Enums\Role;
use App\Livewire\Finance\PettyCash;
use App\Models\PettyCashExpense;
use App\Models\PettyCashFund;
use App\Notifications\PettyCashNotice;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Petty cash, imprest: a fixed fund with a custodian; expenses with receipts; Accounting
// releases the replenishment and the fund is whole again.

beforeEach(function () {
    Notification::fake();
    Storage::fake((string) config('payroll.concerns.disk'));
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00'));

    $this->accounting = staff(Role::Accounting);
    $this->custodian = staff(Role::Admin);
    $this->fund = app(SavePettyCashFund::class)->handle(null, ['name' => 'Head Office petty cash', 'office_id' => null, 'custodian_id' => $this->custodian->id, 'amount' => 5000.0, 'is_active' => true], $this->accounting);
});

function spend(object $test, float $amount, string $to = 'National Book Store'): PettyCashExpense
{
    return app(RecordPettyCashExpense::class)->handle($test->fund, $test->custodian, CarbonImmutable::today(), $amount, $to, 'Bond paper for payroll printouts', UploadedFile::fake()->image('receipt.jpg'));
}

it('is set up by Accounting, and one person keeps one fund', function () {
    expect(fn () => app(SavePettyCashFund::class)->handle(null, ['name' => 'Other', 'office_id' => null, 'custodian_id' => $this->custodian->id, 'amount' => 1000.0, 'is_active' => true], $this->custodian))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(SavePettyCashFund::class)->handle(null, ['name' => 'Other', 'office_id' => null, 'custodian_id' => $this->custodian->id, 'amount' => 1000.0, 'is_active' => true], $this->accounting))
        ->toThrow(ValidationException::class, 'already keeps another fund');

    expect(PettyCashFund::custodianOf($this->custodian)?->is($this->fund))->toBeTrue()
        ->and($this->fund->cashOnHand())->toBe(5000.0);
});

it('logs expenses with receipts, never more than the cash on hand', function () {
    $expense = spend($this, 1200);
    Storage::disk((string) config('payroll.concerns.disk'))->assertExists($expense->receipt_path);

    expect($this->fund->cashOnHand())->toBe(3800.0);
    expect(fn () => spend($this, 4000))->toThrow(ValidationException::class, 'More than the cash on hand (₱3,800.00)');
    expect(fn () => app(RecordPettyCashExpense::class)->handle($this->fund, $this->accounting, CarbonImmutable::today(), 10, 'X', 'Something', UploadedFile::fake()->image('r.jpg')))
        ->toThrow(AuthorizationException::class); // only the custodian
});

it('is replenished by Accounting, and the fund is whole again', function () {
    spend($this, 1200);
    spend($this, 300, 'Jollibee');

    $request = app(RequestReplenishment::class)->handle($this->fund, $this->custodian);
    expect((float) $request->amount)->toBe(1500.0)
        ->and($this->fund->cashOnHand())->toBe(3500.0); // still out until released
    Notification::assertSentTo($this->accounting, PettyCashNotice::class, fn (PettyCashNotice $notice) => $notice->event === PettyCashNotice::REQUESTED);

    // One at a time; and the custodian can't remove an expense that's in it.
    expect(fn () => app(RequestReplenishment::class)->handle($this->fund, $this->custodian))->toThrow(ValidationException::class);
    expect(fn () => app(DeletePettyCashExpense::class)->handle(PettyCashExpense::query()->firstOrFail(), $this->custodian))->toThrow(ValidationException::class, 'in a replenishment request');

    app(DecideReplenishment::class)->handle($request, true, null, $this->accounting);
    expect($request->fresh()?->status)->toBe(ReplenishmentStatus::Released)
        ->and($this->fund->cashOnHand())->toBe(5000.0);
    Notification::assertSentTo($this->custodian, PettyCashNotice::class, fn (PettyCashNotice $notice) => $notice->event === PettyCashNotice::RELEASED);
});

it('returns a request with a reason, freeing its expenses to fix', function () {
    spend($this, 800);
    $request = app(RequestReplenishment::class)->handle($this->fund, $this->custodian);

    expect(fn () => app(DecideReplenishment::class)->handle($request, false, '', $this->accounting))->toThrow(ValidationException::class, 'what to fix');
    app(DecideReplenishment::class)->handle($request, false, 'The receipt is unreadable.', $this->accounting);

    $expense = PettyCashExpense::query()->sole();
    expect($expense->petty_cash_replenishment_id)->toBeNull();
    app(DeletePettyCashExpense::class)->handle($expense, $this->custodian);
    expect($this->fund->cashOnHand())->toBe(5000.0);
});

it('opens for Accounting, leadership and the custodian only', function () {
    $this->actingAs($this->custodian)->get(route('petty-cash'))->assertOk()->assertSee('Head Office petty cash');
    $this->actingAs(staff(Role::President))->get(route('petty-cash'))->assertOk();
    $this->actingAs(staff(Role::Hr))->get(route('petty-cash'))->assertForbidden();

    $personal = collect(Navigation::for($this->custodian))->firstWhere('title', 'Personal');
    expect(array_column($personal['items'] ?? [], 'label'))->toContain('Petty Cash');

    // The receipt: the custodian and Accounting, not others.
    $expense = spend($this, 100);
    $this->actingAs($this->accounting)->get(route('petty-cash.receipt', $expense))->assertOk();
    $this->actingAs(staff(Role::Hr))->get(route('petty-cash.receipt', $expense))->assertForbidden();

    Livewire::actingAs($this->custodian)->test(PettyCash::class)->assertSee('Ask to replenish ₱100.00');
});
