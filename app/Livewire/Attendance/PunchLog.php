<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Services\Attendance\AttendanceCalculator;
use App\Support\DayHeading;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * My Attendance → Punch Log: the proof recorded for each of your shifts, one row per
 * shift under a heading per day (like the Attendance Log supervisors see): clock-in and
 * clock-out side by side, each with its selfie, time, place and map link. Hours and
 * status are on Attendance History, so they aren't repeated here.
 */
class PunchLog extends Component
{
    use InteractsWithUser, WithPagination;

    /**
     * @return LengthAwarePaginator<int, TimeLog>
     */
    #[Computed]
    public function logs(): LengthAwarePaginator
    {
        return TimeLog::query()
            ->with(['punches', 'shift'])
            ->where('user_id', $this->currentUser()->id)
            ->orderByDesc('log_date')
            ->orderByDesc('clock_in_at')
            ->orderByDesc('id')
            ->paginate(10, pageName: 'punchesPage');
    }

    public function dayHeading(CarbonImmutable $date): string
    {
        return DayHeading::for($date);
    }

    /** An open shift: "Not clocked out yet" while it runs, "No clock-out recorded" once it has ended. */
    public function missingClockOut(TimeLog $log): string
    {
        $end = app(AttendanceCalculator::class)->shiftWindow($log->shift ?? Shift::fallback(), $log->log_date)[1];

        return CarbonImmutable::now()->lte($end) ? 'Not clocked out yet' : 'No clock-out recorded';
    }

    #[On('punch-recorded')]
    public function reloadPunches(): void
    {
        unset($this->logs);
    }

    public function render(): View
    {
        return view('livewire.attendance.punch-log');
    }
}
