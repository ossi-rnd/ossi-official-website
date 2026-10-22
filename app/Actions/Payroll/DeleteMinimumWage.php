<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\MinimumWage;
use App\Models\User;
use App\Queries\Payroll\FrozenPayrollUntilQuery;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteMinimumWage
{
    public function __construct(
        private readonly FrozenPayrollUntilQuery $frozen,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(MinimumWage $wage, User $actor): void
    {
        if (! $actor->can(Ability::ManagePayroll->value)) {
            throw new AuthorizationException;
        }

        if ($this->frozen->tooEarly($wage->effective_from) !== null) {
            throw ValidationException::withMessages(['wageFrom' => 'Generated payslips already used this minimum wage, so it stays.']);
        }

        DB::transaction(function () use ($wage, $actor) {
            $this->audit->record(AuditArea::Settings, 'minimum_wage.deleted', "Deleted the minimum wage from {$wage->effective_from->format('M j, Y')}", $wage, ['old' => ['daily_amount' => $wage->daily_amount, 'wage_order' => $wage->wage_order]], causer: $actor);
            $wage->delete();
        });
    }
}
