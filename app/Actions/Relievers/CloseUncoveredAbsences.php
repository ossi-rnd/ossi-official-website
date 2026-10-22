<?php

namespace App\Actions\Relievers;

use App\Enums\AbsenceNoticeStatus;
use App\Enums\AuditArea;
use App\Models\AbsenceNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;

/**
 * Absences whose day has passed with no reliever sent: "Not covered". Until now
 * they stayed "Needs reliever" forever, cluttering Relievers and hiding the one
 * fact that matters afterwards: the post went without a guard that day. Kept as
 * a record (a client will ask), never deleted. Run daily by app:close-uncovered-absences.
 */
final class CloseUncoveredAbsences
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return int how many were closed
     */
    public function handle(CarbonImmutable $today): int
    {
        $closed = 0;

        AbsenceNotice::query()
            ->where('status', AbsenceNoticeStatus::Open)
            ->whereDate('duty_date', '<', $today->toDateString())
            ->with('user')
            ->orderBy('id')
            ->each(function (AbsenceNotice $notice) use (&$closed) {
                $notice->forceFill(['status' => AbsenceNoticeStatus::NotCovered])->save();
                $this->audit->record(AuditArea::Operations, 'absence.not_covered', "{$notice->placeLabel()} went without a guard on {$notice->duty_date->format('M j, Y')} ({$notice->user->name} absent, no reliever)", $notice);
                $closed++;
            });

        return $closed;
    }
}
