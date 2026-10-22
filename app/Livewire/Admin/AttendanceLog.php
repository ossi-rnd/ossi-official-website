<?php

namespace App\Livewire\Admin;

use App\Actions\Attendance\SetGuardPhone;
use App\Livewire\Concerns\FiltersByArea;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Models\TimeLogPunch;
use App\Services\Attendance\AttendanceCalculator;
use App\Support\DayHeading;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * One row per shift (a TimeLog): clock-in and clock-out side by side, newest day
 * first under date headings, instead of one row per punch. Entries Operations made
 * by hand (no selfie) are here too.
 *
 * "On duty" and "Not clocked out" both mean no clock-out yet; which one depends on
 * whether the shift has ended (AttendanceCalculator::shiftWindow), so a night shift
 * still running after midnight isn't flagged. No extra grace number to set.
 *
 * @property-read array{onDuty: list<int>, notClockedOut: list<int>} $open Livewire's cached open()
 */
#[Title('Attendance Log')]
class AttendanceLog extends Component
{
    use FiltersByArea, InteractsWithUser, WithPagination;

    public const ALL = 'all';

    public const ON_DUTY = 'on-duty';

    public const LATE = 'late';

    public const NOT_CLOCKED_OUT = 'not-clocked-out';

    /** A guard punched on a phone that isn't their company phone (CheckPunchDevice). */
    public const OTHER_PHONE = 'other-phone';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'to', except: '')]
    public string $dateTo = '';

    #[Url(as: 'show', except: self::ALL)]
    public string $show = self::ALL;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'dateFrom', 'dateTo', 'show'], true)) {
            $this->resetPage();
        }
    }

    public function showOnly(string $view): void
    {
        $this->show = in_array($view, [self::ON_DUTY, self::LATE, self::NOT_CLOCKED_OUT, self::OTHER_PHONE], true) ? $view : self::ALL;
        $this->resetPage();
    }

    /**
     * Shifts with a clock-in and no clock-out yet, within the filters, split by whether
     * the shift is still running. Few at any time: each is closed by its clock-out.
     *
     * @return array{onDuty: list<int>, notClockedOut: list<int>}
     */
    #[Computed]
    public function open(): array
    {
        $now = CarbonImmutable::now();
        $split = ['onDuty' => [], 'notClockedOut' => []];

        $this->filtered()->with('shift')->whereNotNull('clock_in_at')->whereNull('clock_out_at')
            ->get()
            ->each(function (TimeLog $log) use ($now, &$split) {
                $split[$now->lte($this->shiftEnd($log)) ? 'onDuty' : 'notClockedOut'][] = $log->id;
            });

        return $split;
    }

    /**
     * @return LengthAwarePaginator<int, TimeLog>
     */
    #[Computed]
    public function logs(): LengthAwarePaginator
    {
        return $this->filtered()
            ->with(['user.employeeProfile', 'user.device', 'shift', 'punches'])
            ->when($this->show === self::ON_DUTY, fn ($query) => $query->whereKey($this->open['onDuty']))
            ->when($this->show === self::NOT_CLOCKED_OUT, fn ($query) => $query->whereKey($this->open['notClockedOut']))
            ->when($this->show === self::LATE, fn ($query) => $query->where('late_minutes', '>', 0))
            ->when($this->show === self::OTHER_PHONE, fn ($query) => $query->whereHas('punches', fn ($punch) => $punch->where('other_device', true)))
            ->orderByDesc('log_date')
            ->orderByDesc('clock_in_at')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /** Shifts with a punch on another phone, within the filters: the tab's count. */
    #[Computed]
    public function otherPhoneCount(): int
    {
        return $this->filtered()->whereHas('punches', fn ($punch) => $punch->where('other_device', true))->count();
    }

    /** A flagged punch's phone becomes the guard's (a replacement company phone). */
    public function makeTheirPhone(int $punchId, SetGuardPhone $setPhone): void
    {
        $punch = TimeLogPunch::query()->whereKey($punchId)->whereHas('timeLog', fn ($log) => $log->whereIn('id', $this->filtered()->select('id')))->firstOrFail();

        try {
            $setPhone->handle($this->currentUser(), $punch);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            $this->dispatch('notify', message: (string) collect($e->errors())->flatten()->first());

            return;
        }

        unset($this->logs);
        $this->dispatch('notify', message: 'Done: that phone is theirs from now on.');
    }

    /** When the shift this log is for ends (the next day for a night shift). */
    public function shiftEnd(TimeLog $log): CarbonImmutable
    {
        return app(AttendanceCalculator::class)->shiftWindow($log->shift ?? Shift::fallback(), $log->log_date)[1];
    }

    /** "Today", "Yesterday", or "Tue, Oct 20, 2026": the heading over a day's shifts. */
    public function dayHeading(CarbonImmutable $date): string
    {
        return DayHeading::for($date);
    }

    /**
     * Who the viewer may see, narrowed by area, name and dates (on the shift's date).
     *
     * @return Builder<TimeLog>
     */
    private function filtered(): Builder
    {
        return TimeLog::query()
            ->whereHas('user', fn ($user) => $user->visibleTo($this->currentUser()))
            // By the client the person is deployed to (a reliever counts under their own).
            ->when($this->hasAreaFilter(), fn ($query) => $query->whereHas('user.employeeProfile', fn ($profile) => $this->filterProfilesByArea($profile)))
            ->when($this->search !== '', fn ($query) => $query->whereHas('user', fn ($user) => $user->where('name', 'like', "%{$this->search}%")))
            ->when($this->isDate($this->dateFrom), fn ($query) => $query->whereDate('log_date', '>=', $this->dateFrom))
            ->when($this->isDate($this->dateTo), fn ($query) => $query->whereDate('log_date', '<=', $this->dateTo));
    }

    private function isDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    public function render(): View
    {
        return view('livewire.admin.attendance-log');
    }
}
