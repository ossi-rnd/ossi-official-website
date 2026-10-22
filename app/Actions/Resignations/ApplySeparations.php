<?php

namespace App\Actions\Resignations;

use App\Enums\AuditArea;
use App\Enums\EmploymentStatus;
use App\Enums\ResignationStatus;
use App\Models\Resignation;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Run daily: the day after an accepted resignation's last day, the employee
 * becomes Resigned, which closes their account (sign-in refused, open
 * sessions ended: EnsureAccountIsActive). Clearance may still be running;
 * the resignation completes when it's done. Idempotent: separated once.
 */
final class ApplySeparations
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CompleteResignation $complete,
    ) {}

    /**
     * @return int how many people were separated
     */
    public function handle(CarbonImmutable $today): int
    {
        $due = Resignation::query()
            ->where('status', ResignationStatus::Accepted)
            ->whereNull('separated_at')
            ->whereDate('last_day', '<', $today->toDateString())
            ->pluck('id');

        foreach ($due as $id) {
            DB::transaction(function () use ($id) {
                $resignation = Resignation::query()->with('user.employeeProfile')->whereKey($id)->lockForUpdate()->first();

                if ($resignation === null || $resignation->separated_at !== null) {
                    return;
                }

                $resignation->user->employeeProfile?->forceFill(['employment_status' => EmploymentStatus::Resigned])->save();
                $resignation->forceFill(['separated_at' => now()])->save();

                $this->audit->record(AuditArea::People, 'resignation.separated', "{$resignation->user->name} resigned: last day was ".$resignation->effectiveLastDay()->format('M j, Y'), $resignation);

                $this->complete->handle($resignation);
            });
        }

        return $due->count();
    }
}
