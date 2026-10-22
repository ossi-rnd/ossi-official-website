<?php

namespace App\Actions\Finance;

use App\Enums\AuditArea;
use App\Models\PettyCashExpense;
use App\Models\PettyCashFund;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Files\PrivateUploadStore;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The custodian logs a payment out of their fund, with the receipt (always). It can't
 * be more than the cash on hand, nor dated in the future. Audited.
 */
final class RecordPettyCashExpense
{
    public function __construct(
        private readonly PrivateUploadStore $uploads,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(PettyCashFund $fund, User $custodian, CarbonImmutable $spentOn, float $amount, string $paidTo, string $purpose, UploadedFile $receipt): PettyCashExpense
    {
        if (! $fund->is_active || $fund->custodian_id !== $custodian->id) {
            throw new AuthorizationException;
        }

        $disk = (string) config('payroll.concerns.disk');
        $stored = $this->uploads->store($receipt, $disk, 'petty-cash/'.$fund->id, 'receipt', ['png', 'jpg', 'pdf'], 'receipt');

        try {
            return DB::transaction(function () use ($fund, $custodian, $spentOn, $amount, $paidTo, $purpose, $stored) {
                // Locked, so two expenses at once can't both fit in the same cash.
                $locked = PettyCashFund::query()->lockForUpdate()->findOrFail($fund->id);
                $onHand = $locked->cashOnHand();

                $errors = array_filter([
                    // The day only: an expense from later today is still today's.
                    'spentOn' => $spentOn->startOfDay()->isAfter(CarbonImmutable::today()) ? 'The date can\'t be in the future.' : null,
                    'amount' => match (true) {
                        $amount <= 0 => 'Enter the amount paid.',
                        $amount > $onHand => 'More than the cash on hand (₱'.number_format($onHand, 2).'). Ask for a replenishment first.',
                        default => null,
                    },
                    'paidTo' => blank($paidTo) ? 'Who was paid (the store, the person).' : null,
                    'purpose' => mb_strlen(trim($purpose)) < 5 ? 'Say what it was for.' : null,
                ]);

                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }

                $expense = new PettyCashExpense;
                $expense->forceFill([
                    'petty_cash_fund_id' => $locked->id,
                    'spent_on' => $spentOn->toDateString(),
                    'amount' => number_format($amount, 2, '.', ''),
                    'paid_to' => trim($paidTo),
                    'purpose' => trim($purpose),
                    'receipt_path' => $stored['path'],
                    'receipt_name' => $stored['name'],
                    'receipt_mime' => $stored['mime'],
                    'recorded_by' => $custodian->id,
                ])->save();

                $this->audit->record(AuditArea::Finance, 'petty_cash.expense_recorded', "{$custodian->name} paid ₱".number_format($amount, 2)." to {$expense->paid_to} from {$locked->name}", $expense, causer: $custodian);

                return $expense;
            });
        } catch (\Throwable $e) {
            $this->uploads->delete($disk, $stored['path']);

            throw $e;
        }
    }
}
