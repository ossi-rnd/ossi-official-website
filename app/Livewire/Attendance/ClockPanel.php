<?php

namespace App\Livewire\Attendance;

use App\Actions\Attendance\RecordPunch;
use App\Actions\Patrols\ExtendAway;
use App\Actions\Patrols\LeavePost;
use App\Actions\Patrols\RecordPatrolScan;
use App\Actions\Patrols\ReturnToPost;
use App\Actions\Patrols\UpdateAwayLocation;
use App\Data\DutyForPunch;
use App\Enums\AwayReason;
use App\Enums\PunchType;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\AwayLog;
use App\Models\Client;
use App\Models\DutyAssignment;
use App\Models\OvertimeRequest;
use App\Models\PatrolCheckpoint;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Queries\Attendance\CurrentDutyQuery;
use App\Queries\Attendance\PatrolRoundsQuery;
use App\Support\DeviceCookie;
use App\Support\DeviceLabel;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * My Attendance: today's card and the clock in/out camera modal. The
 * camera, GPS and canvas work is Alpine (resources/js/clock-capture.js);
 * this component only receives the finished punch.
 *
 * @property-read TimeLog|null $timeLog
 * @property-read Shift $shift
 * @property-read PunchType|null $nextPunch
 * @property-read OvertimeRequest|null $todayOvertime
 * @property-read DutyForPunch $duty
 * @property-read Collection<int, DutyAssignment> $upcoming
 * @property-read array{rounds: list<array{index: int, from: CarbonImmutable, to: CarbonImmutable, state: string, scanned: list<int>, missing: list<string>, suspicious: string|null}>, checkpoints: \Illuminate\Support\Collection<int, PatrolCheckpoint>} $patrol
 * @property-read AwayLog|null $away
 */
#[Title('My Attendance')]
class ClockPanel extends Component
{
    use InteractsWithUser;

    /**
     * Today's log, or last night's still-open overnight log.
     */
    #[Computed]
    public function timeLog(): ?TimeLog
    {
        $today = CarbonImmutable::today();

        return $this->currentUser()->timeLogs()
            ->with('shift')
            ->whereDate('log_date', '>=', $today->subDay()->toDateString())
            ->orderByDesc('log_date')
            ->get()
            ->first(fn (TimeLog $log) => $log->log_date->isSameDay($today) || ($log->isOpen() && ($log->shift?->crossesMidnight() ?? false)));
    }

    /**
     * What a clock-in now would be for: the rostered duty, or the usual shift.
     */
    #[Computed]
    public function duty(): DutyForPunch
    {
        return app(CurrentDutyQuery::class)->handle($this->currentUser(), CarbonImmutable::now());
    }

    /**
     * The schedule the day is measured against: the one clocked in on, else today's duty.
     */
    #[Computed]
    public function shift(): Shift
    {
        return $this->timeLog->shift ?? $this->duty->shift;
    }

    /**
     * The next week of duties, so a guard knows where to be.
     *
     * @return Collection<int, DutyAssignment>
     */
    #[Computed]
    public function upcoming(): Collection
    {
        $today = CarbonImmutable::today();

        return $this->currentUser()->dutyAssignments()
            ->with(['shift', 'client'])
            ->whereDate('duty_date', '>=', $today->toDateString())
            ->whereDate('duty_date', '<=', $today->addDays(6)->toDateString())
            ->orderBy('duty_date')
            ->get();
    }

    /**
     * The patrol rounds of the duty clocked in on (empty unless it's a roving duty).
     *
     * @return array{rounds: list<array{index: int, from: CarbonImmutable, to: CarbonImmutable, state: string, scanned: list<int>, missing: list<string>, suspicious: string|null}>, checkpoints: \Illuminate\Support\Collection<int, PatrolCheckpoint>}
     */
    #[Computed]
    public function patrol(): array
    {
        return $this->timeLog?->isOpen()
            ? app(PatrolRoundsQuery::class)->handle($this->timeLog, CarbonImmutable::now())
            : ['rounds' => [], 'checkpoints' => new \Illuminate\Support\Collection];
    }

    #[Computed]
    public function away(): ?AwayLog
    {
        return AwayLog::query()->with('latestLocation')->where('user_id', $this->currentUser()->id)->open()->latest('left_at')->first();
    }

    /**
     * Called from the scanner with the QR text, the camera frame and GPS.
     *
     * @return array{ok: bool, message: string}
     */
    public function submitScan(RecordPatrolScan $record, string $code, string $photo, float $latitude, float $longitude, ?int $accuracy = null): array
    {
        try {
            $scan = $record->handle($this->currentUser(), $code, $photo, $latitude, $longitude, $accuracy);
        } catch (ValidationException $e) {
            return ['ok' => false, 'message' => (string) collect($e->errors())->flatten()->first()];
        }

        unset($this->patrol);
        $this->dispatch('notify', message: "{$scan->checkpoint->name} scanned at {$scan->scanned_at->format('g:i A')}.");

        return ['ok' => true, 'message' => "{$scan->checkpoint->name} ✓ {$scan->scanned_at->format('g:i A')}"];
    }

    /** '' = the first reason offered (they differ for guards and office staff). */
    public string $awayReason = '';

    public string $awayNote = '';

    /**
     * How long they expect to be, in minutes. Required, with nothing suggested: a
     * suggested "usually 2 hrs" became the plan even for a 20-minute errand, and was
     * never late. Now they're held to their own word.
     */
    public string $awayMinutes = '';

    /**
     * @return array{ok: bool, message: string}
     */
    public function leavePost(LeavePost $leave, ?float $latitude = null, ?float $longitude = null): array
    {
        $this->awayReason = $this->awayReason !== '' ? $this->awayReason : AwayReason::for($this->currentUser()->employeeProfile->is_office_staff ?? true)[0]->value;

        if ($this->awayMinutes === '') {
            return ['ok' => false, 'message' => 'Choose how long you\'ll be out. Coming back early is fine.'];
        }

        $this->validate([
            'awayReason' => ['required', Rule::enum(AwayReason::class)],
            'awayNote' => ['nullable', 'string', 'max:500'],
            'awayMinutes' => ['required', Rule::in(array_map('strval', config('attendance.away.durations')))],
        ]);

        try {
            $away = $leave->handle($this->currentUser(), AwayReason::from($this->awayReason), $this->awayNote, $latitude, $longitude, (int) $this->awayMinutes);
        } catch (ValidationException $e) {
            return ['ok' => false, 'message' => (string) collect($e->errors())->flatten()->first()];
        }

        $this->reset(['awayReason', 'awayNote', 'awayMinutes']);
        unset($this->away);
        $back = $away->expectedBackAt()->format('g:i A');
        $this->dispatch('notify', message: ($this->currentUser()->employeeProfile->is_office_staff ?? true) ? "Marked out until {$back}. Tap \"Back at the office\" when you return." : "Marked away until {$back}. Tap \"Back at post\" when you return.");

        return ['ok' => true, 'message' => 'Marked away.'];
    }

    /**
     * While away: "I need more time", with a short reason. No approval needed.
     *
     * @return array{ok: bool, message: string}
     */
    public function extendAway(ExtendAway $extend, int $minutes, string $reason): array
    {
        try {
            $extension = $extend->handle($this->currentUser(), $minutes, $reason);
        } catch (ValidationException $e) {
            return ['ok' => false, 'message' => (string) collect($e->errors())->flatten()->first()];
        }

        unset($this->away);
        $this->dispatch('notify', message: "Time added. Back by {$extension->new_back_at->format('g:i A')}.");

        return ['ok' => true, 'message' => 'Time added.'];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function returnToPost(ReturnToPost $return, ?float $latitude = null, ?float $longitude = null, ?int $accuracy = null): array
    {
        try {
            $away = $return->handle($this->currentUser(), $latitude, $longitude, $accuracy);
        } catch (ValidationException $e) {
            return ['ok' => false, 'message' => (string) collect($e->errors())->flatten()->first()];
        }

        unset($this->away);
        $place = ($this->currentUser()->employeeProfile->is_office_staff ?? true) ? 'at the office' : 'at post';
        $this->dispatch('notify', message: "Back {$place} after {$away->minutesAway(CarbonImmutable::now())} min.");

        return ['ok' => true, 'message' => 'Back at post.'];
    }

    /**
     * While away: share where I am now (asked every few minutes).
     *
     * @return array{ok: bool, message: string}
     */
    public function updateLocation(UpdateAwayLocation $update, float $latitude, float $longitude, ?int $accuracy = null): array
    {
        try {
            $update->handle($this->currentUser(), $latitude, $longitude, $accuracy);
        } catch (ValidationException $e) {
            return ['ok' => false, 'message' => (string) collect($e->errors())->flatten()->first()];
        }

        unset($this->away);
        $this->dispatch('notify', message: ($this->currentUser()->employeeProfile->is_office_staff ?? true) ? 'Location shared with the President or Vice President.' : 'Location shared with Operations.');

        return ['ok' => true, 'message' => 'Location shared.'];
    }

    /**
     * The post whose geofence applies to the next punch (for the dev-only simulate button).
     */
    private function punchSite(): ?Client
    {
        $this->timeLog?->loadMissing('dutyAssignment.client');

        return $this->timeLog->dutyAssignment->client ?? $this->duty->site;
    }

    #[Computed]
    public function nextPunch(): ?PunchType
    {
        return match (true) {
            $this->timeLog === null => PunchType::ClockIn,
            $this->timeLog->isOpen() => PunchType::ClockOut,
            default => null,
        };
    }

    #[Computed]
    public function todayOvertime(): ?OvertimeRequest
    {
        return $this->currentUser()->overtimeRequests()
            ->whereDate('date', CarbonImmutable::today()->toDateString())
            ->latest()
            ->first();
    }

    /**
     * Called from Alpine with the finished capture. Returns a result instead of
     * using the error bag, because the camera modal is wire:ignore'd (a
     * re-render would reset the live video) and so can't show Blade errors.
     *
     * @param  string|null  $model  the phone's model code from the browser (Android Chrome etc.), shown with its brand
     * @return array{ok: bool, message: string}
     */
    public function submitPunch(RecordPunch $recordPunch, string $type, string $photo, float $latitude, float $longitude, ?int $accuracy = null, ?string $address = null, ?string $model = null): array
    {
        $punchType = PunchType::tryFrom($type);

        if ($punchType === null) {
            return ['ok' => false, 'message' => 'Unknown punch type.'];
        }

        try {
            // Which phone: its cookie code (new phones get one now) and what it is, for people to read.
            $recordPunch->handle($this->currentUser(), $punchType, $photo, $latitude, $longitude, $accuracy, $address,
                DeviceCookie::token(), DeviceLabel::fromUserAgent(request()->userAgent(), $model));
        } catch (ValidationException $e) {
            unset($this->timeLog, $this->nextPunch, $this->duty);

            return ['ok' => false, 'message' => (string) collect($e->errors())->flatten()->first()];
        }

        unset($this->timeLog, $this->nextPunch, $this->duty, $this->shift, $this->patrol, $this->away);

        $message = $punchType === PunchType::ClockIn ? 'Clocked in. Have a safe shift.' : 'Clocked out. See you next shift.';
        $this->dispatch('punch-recorded');
        $this->dispatch('notify', message: $message);

        return ['ok' => true, 'message' => $message];
    }

    public function render(): View
    {
        // A guard's post, or an office staffer's OSSI office (where their clock-in must be).
        $profile = $this->currentUser()->employeeProfile;
        $site = $this->punchSite() ?? ($profile?->is_office_staff ? $profile->office : null);

        return view('livewire.attendance.clock-panel', [
            'site' => $site,
            'isOffice' => $isOffice = ($this->currentUser()->employeeProfile->is_office_staff ?? true),
            'awayReasons' => AwayReason::for($isOffice),
            'awayDurations' => config('attendance.away.durations'),
            'awayExtensions' => config('attendance.away.extensions'),
            // Local only: the simulate button punches from the post, so the geofence lets it through.
            'simulateAt' => app()->isLocal() && $site?->hasGeofence() ? ['latitude' => (float) $site->latitude, 'longitude' => (float) $site->longitude] : null,
        ]);
    }
}
