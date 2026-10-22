<?php

namespace App\Actions\Finance;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\PettyCashFund;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounting sets up or edits a petty cash fund: its name, office, custodian and
 * amount. The amount can't go below what's already spent and not yet replenished
 * (the cash would be negative), and one person keeps one fund. Audited.
 */
final class SavePettyCashFund
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, office_id: int|null, custodian_id: int, amount: float, is_active: bool}  $data
     */
    public function handle(?PettyCashFund $fund, array $data, User $actor): PettyCashFund
    {
        if (! $actor->can(Ability::ManagePettyCash->value)) {
            throw new AuthorizationException;
        }

        $custodian = User::query()->onPayroll()->find($data['custodian_id']);

        $errors = array_filter([
            'fundAmount' => match (true) {
                $data['amount'] < 100 || $data['amount'] > 1000000 => 'From ₱100 to ₱1,000,000.',
                $fund !== null && $data['amount'] < $fund->spent() => 'It can\'t be less than what\'s spent and not yet replenished (₱'.number_format($fund->spent(), 2).'). Replenish first.',
                default => null,
            },
            'custodianId' => match (true) {
                $custodian === null => 'Choose someone who works here.',
                $data['is_active'] && PettyCashFund::query()->where('custodian_id', $custodian->id)->where('is_active', true)->when($fund, fn ($query, PettyCashFund $existing) => $query->whereKeyNot($existing->id))->exists() => "{$custodian->name} already keeps another fund.",
                default => null,
            },
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($fund, $data, $actor) {
            $isNew = $fund === null;
            $fund ??= new PettyCashFund;
            $fields = ['name', 'office_id', 'custodian_id', 'amount', 'is_active'];
            $before = $isNew ? [] : $fund->only($fields);
            $oldCustodian = $fund->custodian_id;

            $fund->forceFill([...$data, 'amount' => number_format($data['amount'], 2, '.', '')])->save();

            $this->audit->record(AuditArea::Finance, $isNew ? 'petty_cash.fund_created' : 'petty_cash.fund_updated', "Saved petty cash fund {$fund->name} (₱".number_format((float) $fund->amount, 2).')', $fund,
                AuditLogger::diff($before, $fund->only($fields)), causer: $actor);

            // A new custodian learns they now keep the cash, and where to log it.
            if ($fund->is_active && $fund->custodian_id !== $oldCustodian) {
                UpdateNotice::tell($fund->custodian()->first(), $actor, "You keep petty cash: {$fund->name}",
                    '₱'.number_format((float) $fund->amount, 2).'. Log each payment with its receipt on Petty Cash, then ask to replenish.', route('petty-cash'), 'wallet', email: true);
            }

            return $fund;
        });
    }
}
