<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\CashBondEntry;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Accounting enters what payslips don't show: the bond someone paid in before
 * the system (the opening balance, once), or a correction with a reason.
 * Entries are never edited; a mistake is fixed with a correction.
 */
final class RecordCashBondEntry
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $employee, string $kind, float $amount, ?string $note, User $actor): CashBondEntry
    {
        if (! $actor->can(Ability::PrepareFinalPay->value)) {
            throw new AuthorizationException;
        }

        if (! in_array($kind, [CashBondEntry::OPENING, CashBondEntry::ADJUSTMENT], true)) {
            throw ValidationException::withMessages(['amount' => 'Only a starting balance or a correction can be entered by hand.']);
        }

        if ($kind === CashBondEntry::OPENING) {
            if ($amount < 0) {
                throw ValidationException::withMessages(['amount' => 'A starting balance can\'t be negative.']);
            }

            if (CashBondEntry::query()->where('user_id', $employee->id)->where('kind', CashBondEntry::OPENING)->exists()) {
                throw ValidationException::withMessages(['amount' => 'The starting balance is already set. Enter a correction instead.']);
            }
        }

        if ($kind === CashBondEntry::ADJUSTMENT && (round($amount, 2) === 0.0 || blank($note))) {
            throw ValidationException::withMessages(['amount' => 'A correction needs an amount (+ or −) and a reason.']);
        }

        $entry = new CashBondEntry;
        $entry->forceFill([
            'user_id' => $employee->id,
            'kind' => $kind,
            'amount' => number_format($amount, 2, '.', ''),
            'note' => filled($note) ? trim((string) $note) : null,
            'recorded_by' => $actor->id,
        ])->save();

        $this->audit->record(AuditArea::Payroll, "cash_bond.{$kind}", ($kind === CashBondEntry::OPENING ? 'Starting cash bond' : 'Cash bond correction')." for {$employee->name}: ₱".number_format($amount, 2), $entry, causer: $actor);

        UpdateNotice::tell($employee, $actor, $kind === CashBondEntry::OPENING ? 'Your cash bond on record: ₱'.number_format($amount, 2) : 'Cash bond corrected: '.($amount >= 0 ? '+' : '−').'₱'.number_format(abs($amount), 2),
            $kind === CashBondEntry::OPENING ? 'What you paid in before this system. It\'s refunded in your final pay.' : 'Accounting: '.trim((string) $note), route('loans'), 'lock');

        return $entry;
    }
}
