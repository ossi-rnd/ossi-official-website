<?php

namespace App\Actions\Settings;

use App\Enums\AuditArea;
use App\Models\LateDeductionRule;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

final class DeleteLateDeductionRule
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(LateDeductionRule $rule, User $actor): void
    {
        DB::transaction(function () use ($rule, $actor) {
            $this->audit->record(AuditArea::Payroll, 'late_rule.deleted', "Deleted late bracket {$rule->rangeLabel()}", $rule, ['old' => $rule->only(['min_minutes', 'max_minutes', 'deduction_amount'])], causer: $actor);

            $rule->delete();
        });
    }
}
