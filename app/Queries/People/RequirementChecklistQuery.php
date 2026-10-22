<?php

namespace App\Queries\People;

use App\Data\RequirementItem;
use App\Enums\RequirementStatus;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRequirement;
use Carbon\CarbonImmutable;

/**
 * The 201 checklist of one employee: every requirement that applies to them
 * (office staff skip the guard-only ones), in enum order, in one query.
 */
final class RequirementChecklistQuery
{
    /**
     * @return list<RequirementItem>
     */
    public function handle(EmployeeProfile $profile, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        $records = $profile->requirements()
            ->with('verifier:id,name')
            ->get()
            ->keyBy(fn (EmployeeRequirement $record) => $record->requirement->value);

        $items = [];

        foreach ($profile->applicableRequirements() as $requirement) {
            /** @var EmployeeRequirement|null $record */
            $record = $records->get($requirement->value);
            $status = $record->status ?? RequirementStatus::Missing;

            $items[] = new RequirementItem(
                requirement: $requirement,
                record: $record,
                status: $status,
                isExpired: $status === RequirementStatus::OnFile && $record?->isExpired($today) === true,
                daysToExpiry: $status === RequirementStatus::OnFile ? $record?->daysToExpiry($today) : null,
                isPromiseOverdue: $status === RequirementStatus::Missing && $record?->promised_on !== null && $record->promised_on->lessThan($today),
            );
        }

        return $items;
    }

    /**
     * @param  list<RequirementItem>  $items
     * @return array{total: int, on_file: int, submitted: int, expired: int}
     */
    public static function summarize(array $items): array
    {
        $count = fn (callable $test) => count(array_filter($items, $test));

        return [
            'total' => count($items),
            'on_file' => $count(fn (RequirementItem $item) => $item->status === RequirementStatus::OnFile && ! $item->isExpired),
            'submitted' => $count(fn (RequirementItem $item) => $item->status === RequirementStatus::Submitted),
            'expired' => $count(fn (RequirementItem $item) => $item->isExpired),
        ];
    }
}
