<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\StatutoryRuleSet;
use App\Models\User;
use App\Queries\Payroll\FrozenPayrollUntilQuery;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounting saves a version of the SSS / PhilHealth / Pag-IBIG / BIR rules
 * from a date. Payroll periods starting on or after it use the new rules;
 * generated payslips never change. The same date again replaces that version
 * (fixing a typo before it's used).
 */
final class SaveStatutoryRuleSet
{
    public function __construct(
        private readonly FrozenPayrollUntilQuery $frozen,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{sss: array<string, float>, philhealth: array<string, float>, pagibig: array<string, float>, withholding_semi_monthly: list<array{over: float, base: float, rate: float}>}  $rules  rates as fractions (0.05)
     */
    public function handle(array $rules, CarbonImmutable $effectiveFrom, ?string $note, User $actor): StatutoryRuleSet
    {
        if (! $actor->can(Ability::ManagePayroll->value)) {
            throw new AuthorizationException;
        }

        if (($message = $this->frozen->tooEarly($effectiveFrom)) !== null) {
            throw ValidationException::withMessages(['effectiveFrom' => $message]);
        }

        $brackets = $rules['withholding_semi_monthly'];
        usort($brackets, fn (array $a, array $b) => $a['over'] <=> $b['over']);

        if ($brackets === [] || $brackets[0]['over'] != 0.0) {
            throw ValidationException::withMessages(['rules.withholding_semi_monthly' => 'The tax table must start with a bracket "over ₱0".']);
        }

        if ($rules['sss']['msc_min'] >= $rules['sss']['msc_max'] || $rules['philhealth']['floor'] >= $rules['philhealth']['ceiling']) {
            throw ValidationException::withMessages(['rules.sss.msc_max' => 'Each maximum must be above its minimum.']);
        }

        $rules['withholding_semi_monthly'] = $brackets;

        return DB::transaction(function () use ($rules, $effectiveFrom, $note, $actor) {
            $version = StatutoryRuleSet::query()->whereDate('effective_from', $effectiveFrom->toDateString())->lockForUpdate()->first() ?? new StatutoryRuleSet;
            $previous = $version->exists ? $version->rules : null;

            $version->forceFill([
                'effective_from' => $effectiveFrom->toDateString(),
                'rules' => $rules,
                'note' => filled($note) ? $note : null,
                'created_by' => $actor->id,
            ])->save();

            $this->audit->record(AuditArea::Settings, 'statutory.saved', "Saved tax and contribution rules from {$effectiveFrom->format('M j, Y')}", $version, [
                'old' => $previous,
                'new' => $rules,
                'note' => $version->note,
            ], causer: $actor);

            return $version;
        });
    }
}
