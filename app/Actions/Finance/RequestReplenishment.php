<?php

namespace App\Actions\Finance;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\ReplenishmentStatus;
use App\Models\PettyCashExpense;
use App\Models\PettyCashFund;
use App\Models\PettyCashReplenishment;
use App\Models\User;
use App\Notifications\PettyCashNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The custodian asks Accounting to replenish the fund: every expense not yet in a
 * request goes in, with their total. One request waits at a time. Accounting is told.
 */
final class RequestReplenishment
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PettyCashFund $fund, User $custodian): PettyCashReplenishment
    {
        if (! $fund->is_active || $fund->custodian_id !== $custodian->id) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($fund, $custodian) {
            $locked = PettyCashFund::query()->lockForUpdate()->findOrFail($fund->id);

            if ($locked->replenishments()->where('status', ReplenishmentStatus::Pending)->exists()) {
                throw ValidationException::withMessages(['replenish' => 'A request is already waiting for Accounting. Add these to the next one.']);
            }

            $expenses = PettyCashExpense::query()->where('petty_cash_fund_id', $locked->id)->whereNull('petty_cash_replenishment_id')->lockForUpdate()->get();

            if ($expenses->isEmpty()) {
                throw ValidationException::withMessages(['replenish' => 'There are no expenses to replenish yet.']);
            }

            $request = new PettyCashReplenishment;
            $request->forceFill([
                'petty_cash_fund_id' => $locked->id,
                'requested_by' => $custodian->id,
                'amount' => number_format((float) $expenses->sum('amount'), 2, '.', ''),
            ])->save();

            PettyCashExpense::query()->whereKey($expenses->modelKeys())->update(['petty_cash_replenishment_id' => $request->id]);

            $this->audit->record(AuditArea::Finance, 'petty_cash.replenishment_requested', "{$custodian->name} asked to replenish {$locked->name}: ₱".number_format((float) $request->amount, 2)." ({$expenses->count()} ".str('expense')->plural($expenses->count()).')', $request, causer: $custodian);

            Notification::send(User::query()->notifiable(Ability::ManagePettyCash->roles())->whereKeyNot($custodian->id)->get(), new PettyCashNotice($request->setRelation('fund', $locked), PettyCashNotice::REQUESTED));

            return $request;
        });
    }
}
