<?php

namespace App\Actions\FinalPay;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\FinalPayStatus;
use App\Enums\ResignationStatus;
use App\Models\FinalPay;
use App\Models\Resignation;
use App\Models\User;
use App\Queries\Payroll\FinalPayStatementQuery;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounting computes (or recomputes) the statement of an accepted
 * resignation, from the last day on: the numbers only settle then. Allowed
 * while it's a draft; once submitted for signatures the statement is frozen
 * (sending it back makes it a draft again).
 */
final class PrepareFinalPay
{
    public function __construct(
        private readonly FinalPayStatementQuery $statement,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Resignation $resignation, User $actor): FinalPay
    {
        if (! $actor->can(Ability::PrepareFinalPay->value)) {
            throw new AuthorizationException;
        }

        if (! in_array($resignation->status, [ResignationStatus::Accepted, ResignationStatus::Completed], true)) {
            throw ValidationException::withMessages(['finalPay' => 'Final pay is prepared once the resignation is accepted.']);
        }

        if (CarbonImmutable::today()->lt($resignation->effectiveLastDay())) {
            throw ValidationException::withMessages(['finalPay' => 'Final pay can be prepared from the last day ('.$resignation->effectiveLastDay()->format('M j, Y').'): until then the numbers still change.']);
        }

        return DB::transaction(function () use ($resignation, $actor) {
            $finalPay = FinalPay::query()->where('resignation_id', $resignation->id)->lockForUpdate()->first() ?? new FinalPay;

            if ($finalPay->exists && $finalPay->status !== FinalPayStatus::Draft) {
                throw ValidationException::withMessages(['finalPay' => 'It has been submitted for signatures. Send it back to change it.']);
            }

            $computed = $this->statement->handle($resignation, $finalPay->adjustments ?? []);

            $finalPay->forceFill([
                'resignation_id' => $resignation->id,
                'user_id' => $resignation->user_id,
                'status' => FinalPayStatus::Draft,
                'lines' => $computed['lines'],
                'total_pay' => $computed['total_pay'],
                'total_deducted' => $computed['total_deducted'],
                'net' => $computed['net'],
                'receivable' => $computed['receivable'],
                'thirteenth_year' => $computed['thirteenth_year'],
                'thirteenth_amount' => $computed['thirteenth_amount'],
                'cash_bond_refund' => $computed['cash_bond_refund'],
                'prepared_by' => $actor->id,
                'prepared_at' => now(),
            ])->save();

            $this->audit->record(AuditArea::Payroll, 'final_pay.prepared', "Prepared {$resignation->user->name}'s final pay: net ₱".number_format($computed['net'], 2), $finalPay, causer: $actor);

            return $finalPay;
        });
    }
}
