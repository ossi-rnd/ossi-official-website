<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\MinimumWage;
use App\Models\User;
use App\Queries\Payroll\FrozenPayrollUntilQuery;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A new wage order: the daily minimum from a date. It decides who is a
 * minimum wage earner (tax exempt) and drives the below-minimum warning.
 */
final class SaveMinimumWage
{
    public function __construct(
        private readonly FrozenPayrollUntilQuery $frozen,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(CarbonImmutable $effectiveFrom, float $dailyAmount, ?string $wageOrder, User $actor): MinimumWage
    {
        if (! $actor->can(Ability::ManagePayroll->value)) {
            throw new AuthorizationException;
        }

        if (($message = $this->frozen->tooEarly($effectiveFrom)) !== null) {
            throw ValidationException::withMessages(['wageFrom' => $message]);
        }

        return DB::transaction(function () use ($effectiveFrom, $dailyAmount, $wageOrder, $actor) {
            $wage = MinimumWage::query()->whereDate('effective_from', $effectiveFrom->toDateString())->lockForUpdate()->first() ?? new MinimumWage;
            $previous = $wage->exists ? (float) $wage->daily_amount : null;

            $wage->forceFill([
                'effective_from' => $effectiveFrom->toDateString(),
                'daily_amount' => number_format($dailyAmount, 2, '.', ''),
                'wage_order' => filled($wageOrder) ? $wageOrder : null,
                'created_by' => $actor->id,
            ])->save();

            $this->audit->record(AuditArea::Settings, 'minimum_wage.saved', 'Minimum wage ₱'.number_format($dailyAmount, 2)." from {$effectiveFrom->format('M j, Y')}", $wage, [
                'old' => ['daily_amount' => $previous],
                'new' => ['daily_amount' => $dailyAmount, 'wage_order' => $wage->wage_order],
            ], causer: $actor);

            return $wage;
        });
    }
}
