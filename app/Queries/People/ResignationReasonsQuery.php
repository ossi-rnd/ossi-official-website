<?php

namespace App\Queries\People;

use App\Enums\ResignationReason;
use App\Enums\ResignationStatus;
use App\Models\Resignation;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Why people leave: how often each reason was ticked on the resignations this
 * person handles, filed in the last 12 months (withdrawn ones not counted),
 * split guards / office staff. One resignation can tick several reasons, so
 * the counts add up to more than the resignations.
 */
final class ResignationReasonsQuery
{
    /**
     * @return array{filed: int, rows: list<array{reason: ResignationReason, guards: int, office: int, total: int}>, others: list<string>}
     */
    public function handle(User $viewer, ?CarbonImmutable $today = null): array
    {
        $since = ($today ?? CarbonImmutable::today())->subYear();
        $counts = [];
        $others = [];
        $filed = 0;

        $resignations = Resignation::query()->handledBy($viewer)
            ->where('created_at', '>=', $since)
            ->where('status', '!=', ResignationStatus::Withdrawn)
            ->with('user.employeeProfile:id,user_id,is_office_staff')
            ->get(['id', 'user_id', 'reason', 'reasons', 'reason_other']);

        foreach ($resignations as $resignation) {
            $filed++;
            $office = (bool) $resignation->user->employeeProfile?->is_office_staff;

            foreach ($resignation->reasonList() as $reason) {
                $counts[$reason->value] ??= ['reason' => $reason, 'guards' => 0, 'office' => 0, 'total' => 0];
                $counts[$reason->value][$office ? 'office' : 'guards']++;
                $counts[$reason->value]['total']++;
            }

            if (filled($resignation->reason_other)) {
                $others[] = (string) $resignation->reason_other;
            }
        }

        $rows = array_values($counts);
        usort($rows, fn (array $a, array $b) => $b['total'] <=> $a['total']);

        return ['filed' => $filed, 'rows' => $rows, 'others' => $others];
    }
}
