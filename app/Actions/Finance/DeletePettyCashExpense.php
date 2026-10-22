<?php

namespace App\Actions\Finance;

use App\Enums\AuditArea;
use App\Models\PettyCashExpense;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Files\PrivateUploadStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The custodian removes an expense logged by mistake, while it isn't in a
 * replenishment request yet. Audited with what it was.
 */
final class DeletePettyCashExpense
{
    public function __construct(
        private readonly PrivateUploadStore $uploads,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(PettyCashExpense $expense, User $custodian): void
    {
        $expense->loadMissing('fund');

        if ($expense->fund->custodian_id !== $custodian->id) {
            throw new AuthorizationException;
        }

        if ($expense->petty_cash_replenishment_id !== null) {
            throw ValidationException::withMessages(['expense' => 'It\'s in a replenishment request already. If it\'s wrong, ask Accounting to return the request.']);
        }

        DB::transaction(function () use ($expense, $custodian) {
            $this->audit->record(AuditArea::Finance, 'petty_cash.expense_deleted', "{$custodian->name} removed a ₱".number_format((float) $expense->amount, 2)." expense ({$expense->paid_to}) from {$expense->fund->name}", $expense,
                ['old' => ['spent_on' => $expense->spent_on->toDateString(), 'amount' => $expense->amount, 'paid_to' => $expense->paid_to, 'purpose' => $expense->purpose]], causer: $custodian);

            $expense->delete();
        });

        $this->uploads->delete((string) config('payroll.concerns.disk'), $expense->receipt_path);
    }
}
