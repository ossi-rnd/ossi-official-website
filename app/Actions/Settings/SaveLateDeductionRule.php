<?php

namespace App\Actions\Settings;

use App\Enums\AuditArea;
use App\Models\LateDeductionRule;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveLateDeductionRule
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{min_minutes: int, max_minutes: int|null, deduction_amount: float|string}  $data
     */
    public function handle(?LateDeductionRule $rule, array $data, User $actor): LateDeductionRule
    {
        return DB::transaction(function () use ($rule, $data, $actor) {
            $this->ensureNoOverlap($rule, $data['min_minutes'], $data['max_minutes']);

            $isNew = $rule === null;
            $rule ??= new LateDeductionRule;
            $before = $isNew ? [] : $rule->only(array_keys($data));

            $rule->fill($data)->save();

            $this->audit->record(AuditArea::Payroll, $isNew ? 'late_rule.created' : 'late_rule.updated', "Saved late bracket {$rule->rangeLabel()}", $rule, AuditLogger::diff($before, $rule->only(array_keys($data))), causer: $actor);

            return $rule;
        });
    }

    /**
     * Overlapping brackets would make "30 minutes late" match two amounts.
     */
    private function ensureNoOverlap(?LateDeductionRule $rule, int $min, ?int $max): void
    {
        $newMax = $max ?? PHP_INT_MAX;

        $overlaps = LateDeductionRule::query()
            ->when($rule !== null, fn ($query) => $query->whereKeyNot($rule?->id))
            ->lockForUpdate()
            ->get()
            ->contains(fn (LateDeductionRule $existing) => $min <= ($existing->max_minutes ?? PHP_INT_MAX) && $existing->min_minutes <= $newMax);

        if ($overlaps) {
            throw ValidationException::withMessages(['minMinutes' => 'This range overlaps an existing bracket.']);
        }
    }
}
