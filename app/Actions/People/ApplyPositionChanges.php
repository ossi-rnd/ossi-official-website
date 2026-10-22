<?php

namespace App\Actions\People;

use App\Enums\AuditArea;
use App\Models\PositionChange;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Daily (00:05): a promotion or change of position whose day has come is put
 * on the employee record. Pay doesn't wait for this (it reads the history), so a
 * late run changes nothing but what the record shows. Once each.
 */
final class ApplyPositionChanges
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return int how many were applied
     */
    public function handle(CarbonImmutable $today): int
    {
        $applied = 0;

        PositionChange::query()->pending()->whereDate('effective_on', '<=', $today->toDateString())->with('profile')->orderBy('effective_on')->get()
            ->each(function (PositionChange $change) use (&$applied) {
                DB::transaction(function () use ($change, &$applied) {
                    $change->profile->forceFill(['position' => $change->to_position])->save();
                    $change->forceFill(['applied_at' => CarbonImmutable::now()])->save();
                    $this->audit->record(AuditArea::People, 'position.applied', "{$change->profile->name} is now {$change->to_position->label()} (from {$change->effective_on->format('M j, Y')})", $change);
                    $applied++;
                });
            });

        return $applied;
    }
}
