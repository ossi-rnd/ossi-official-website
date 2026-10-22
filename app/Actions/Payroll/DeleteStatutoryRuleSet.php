<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\StatutoryRuleSet;
use App\Models\User;
use App\Queries\Payroll\FrozenPayrollUntilQuery;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Removes a version that no generated payslip used yet (e.g. entered too early).
 */
final class DeleteStatutoryRuleSet
{
    public function __construct(
        private readonly FrozenPayrollUntilQuery $frozen,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(StatutoryRuleSet $version, User $actor): void
    {
        if (! $actor->can(Ability::ManagePayroll->value)) {
            throw new AuthorizationException;
        }

        if ($this->frozen->tooEarly($version->effective_from) !== null) {
            throw ValidationException::withMessages(['effectiveFrom' => 'Generated payslips already use this version, so it stays. Add a newer version instead.']);
        }

        DB::transaction(function () use ($version, $actor) {
            $this->audit->record(AuditArea::Settings, 'statutory.deleted', "Deleted the tax and contribution rules from {$version->effective_from->format('M j, Y')}", $version, ['old' => $version->rules], causer: $actor);
            $version->delete();
        });
    }
}
