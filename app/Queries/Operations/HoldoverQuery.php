<?php

namespace App\Queries\Operations;

use App\Models\AbsenceNotice;
use App\Models\TimeLog;
use App\Services\Attendance\AttendanceCalculator;
use Illuminate\Support\Collection;

/**
 * Who could hold the post until a reliever arrives: guards still clocked in
 * at the same client right now. The guard going off at that very post (the
 * night guard when the day guard is missing) comes first, then whoever's
 * shift ends closest to the missing one's start.
 */
final class HoldoverQuery
{
    public function __construct(private readonly AttendanceCalculator $calculator) {}

    /**
     * @return Collection<int, array{name: string, label: string}>
     */
    public function handle(AbsenceNotice $notice): Collection
    {
        if ($notice->client_id === null) {
            return collect();
        }

        $start = $notice->dutyWindow()['start'];

        return TimeLog::query()
            ->whereNotNull('clock_in_at')->whereNull('clock_out_at')
            ->whereDate('log_date', '>=', $notice->duty_date->subDay()->toDateString())
            ->where('user_id', '!=', $notice->user_id)
            ->whereHas('dutyAssignment', fn ($duty) => $duty->where('client_id', $notice->client_id))
            ->with(['user:id,name', 'dutyAssignment.shift'])
            ->get()
            ->map(function (TimeLog $log) use ($notice, $start) {
                $duty = $log->dutyAssignment;
                $end = $duty !== null ? $this->calculator->shiftWindow($duty->shift, $log->log_date)[1] : $start;

                return [
                    'name' => $log->user->name,
                    'label' => $log->user->name.' ('.($duty->shift->name ?? 'on duty').', until '.$end->format('g:i A').($duty?->post ? ', '.$duty->post : '').')',
                    'samePost' => $notice->post !== null && $duty?->post === $notice->post,
                    'gap' => abs($end->diffInMinutes($start)),
                ];
            })
            ->sortBy([['samePost', 'desc'], ['gap', 'asc']])
            ->map(fn (array $row) => ['name' => $row['name'], 'label' => $row['label']])
            ->values();
    }

    /** "Pedro Reyes (Night Duty, until 6:00 AM, Main Entrance)", or null when nobody is there. */
    public function first(AbsenceNotice $notice): ?string
    {
        return $this->handle($notice)->first()['label'] ?? null;
    }
}
