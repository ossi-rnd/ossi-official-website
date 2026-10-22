<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Shift;
use App\Queries\Attendance\AttendanceHistoryQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The signed-in user's own history: a rolling window, a monthly DTR, or a
 * yearly rollup. All querying lives in AttendanceHistoryQuery.
 *
 * @phpstan-import-type Day from AttendanceHistoryQuery
 * @phpstan-import-type Summary from AttendanceHistoryQuery
 * @phpstan-import-type MonthRow from AttendanceHistoryQuery
 *
 * @property-read list<Day> $history
 * @property-read list<MonthRow> $yearly
 * @property-read Summary $summary
 * @property-read int $daysInRange
 * @property-read float $shiftHours
 */
class History extends Component
{
    use InteractsWithUser;

    public string $mode = 'recent';

    public int $days = 7;

    #[Locked]
    public string $month = '';

    #[Locked]
    public int $year = 0;

    public function mount(): void
    {
        $this->month = CarbonImmutable::today()->format('Y-m');
        $this->year = CarbonImmutable::today()->year;
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function history(): array
    {
        [$start, $end] = $this->mode === 'month'
            ? $this->monthRange()
            : [CarbonImmutable::today()->subDays($this->days - 1), CarbonImmutable::today()];

        return app(AttendanceHistoryQuery::class)->handle($this->currentUser(), $start, $end);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function yearly(): array
    {
        return app(AttendanceHistoryQuery::class)->monthlyBreakdown($this->currentUser(), $this->year);
    }

    /**
     * @return array<string, int|float>
     */
    #[Computed]
    public function summary(): array
    {
        $query = app(AttendanceHistoryQuery::class);

        return $this->mode === 'year' ? $query->summarizeMonths($this->yearly) : $query->summarize($this->history);
    }

    #[Computed]
    public function daysInRange(): int
    {
        return $this->mode === 'year'
            ? (int) collect($this->yearly)->sum('daysInRange')
            : collect($this->history)->whereNotIn('status', ['not_hired', 'upcoming'])->count();
    }

    #[Computed]
    public function shiftHours(): float
    {
        $user = $this->currentUser()->loadMissing('employeeProfile.shift');

        return round(($user->employeeProfile->shift ?? Shift::fallback())->scheduledMinutes() / 60, 1);
    }

    public function setMode(string $mode): void
    {
        if (in_array($mode, ['recent', 'month', 'year'], true)) {
            $this->mode = $mode;
        }
    }

    public function setRange(int $days): void
    {
        if (in_array($days, [7, 15, 30], true)) {
            $this->days = $days;
            $this->mode = 'recent';
        }
    }

    public function previousMonth(): void
    {
        $this->mode = 'month';
        $this->month = CarbonImmutable::parse($this->month.'-01')->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $next = CarbonImmutable::parse($this->month.'-01')->addMonthNoOverflow();
        $this->mode = 'month';

        if ($next->lte(CarbonImmutable::today()->startOfMonth())) {
            $this->month = $next->format('Y-m');
        }
    }

    public function previousYear(): void
    {
        $this->mode = 'year';
        $this->year = max($this->currentUser()->created_at->year, $this->year - 1);
    }

    public function nextYear(): void
    {
        $this->mode = 'year';
        $this->year = min(CarbonImmutable::today()->year, $this->year + 1);
    }

    public function viewMonth(string $ym): void
    {
        if (preg_match('/^\d{4}-\d{2}$/', $ym) === 1) {
            $this->month = $ym;
            $this->mode = 'month';
        }
    }

    public function exportCsv(): StreamedResponse
    {
        $rows = collect($this->history)->map(fn (array $day) => [
            $day['label'], $day['clockIn'] ?? '', $day['clockOut'] ?? '', $day['hours'] ?? '', str((string) $day['status'])->replace('_', ' ')->title(),
        ]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fputcsv($out, ['Date', 'Time In', 'Time Out', 'Hours', 'Status']);
            $rows->each(fn (array $row) => fputcsv($out, $row));
            fclose($out);
        }, 'my-attendance-'.($this->mode === 'month' ? $this->month : "last-{$this->days}-days").'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function monthRange(): array
    {
        $start = CarbonImmutable::parse($this->month.'-01')->startOfMonth();
        $end = $start->isSameMonth(today()) ? CarbonImmutable::today() : $start->endOfMonth()->startOfDay();

        return [$start, $end];
    }

    public function render(): View
    {
        return view('livewire.attendance.history');
    }
}
