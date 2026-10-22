<?php

namespace App\Actions\Roster;

use App\Enums\AuditArea;
use App\Enums\TimeLogStatus;
use App\Models\DutyAssignment;
use App\Models\TimeLog;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operations records a duty's attendance by hand when the guard's phone or
 * GPS failed (or the post is outside coverage). It's the exception, so it
 * needs a written reason, is marked "manual" everywhere, and is audited.
 *
 * Never over a punched record: a selfie punch is evidence and stays as it is.
 * Times are checked against the duty: no future times, clock-out after
 * clock-in, at most 24 hours.
 */
final class RecordManualAttendance
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(DutyAssignment $duty, CarbonImmutable $clockIn, ?CarbonImmutable $clockOut, string $reason, User $actor): TimeLog
    {
        $duty->loadMissing(['user', 'client', 'shift']);
        RosterRules::authorize($duty->user, $duty->client, $actor);

        $now = CarbonImmutable::now();
        [$start] = $this->calculator->shiftWindow($duty->shift, $duty->duty_date);

        $errors = array_filter([
            'manualReason' => mb_strlen(trim($reason)) < 10 ? 'Say why it was recorded by hand (at least a few words).' : null,
            'manualIn' => match (true) {
                $clockIn->gt($now) => 'The clock-in can\'t be in the future.',
                $clockIn->lt($start->subHours(2)) || $clockIn->gt($start->addHours(12)) => 'The clock-in must be near this duty\'s start ('.$start->format('M j, g:i A').').',
                default => null,
            },
            'manualOut' => match (true) {
                $clockOut === null => null,
                $clockOut->gt($now) => 'The clock-out can\'t be in the future.',
                $clockOut->lte($clockIn) => 'The clock-out must be after the clock-in.',
                $clockIn->diffInHours($clockOut) > 24 => 'A duty can\'t be longer than 24 hours.',
                default => null,
            },
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($duty, $clockIn, $clockOut, $reason, $actor) {
            $log = TimeLog::query()->where('user_id', $duty->user_id)->whereDate('log_date', $duty->duty_date->toDateString())->lockForUpdate()->first();

            if ($log !== null && $log->punches()->exists()) {
                throw ValidationException::withMessages(['manualIn' => "{$duty->user->name} punched in with a selfie for this duty. That record stays as it is."]);
            }

            $before = $log?->only(['clock_in_at', 'clock_out_at']) ?? [];
            $log ??= new TimeLog;
            $late = $this->calculator->lateMinutes($clockIn, $duty->shift, $duty->duty_date);
            $undertime = $clockOut !== null ? $this->calculator->undertimeMinutes($clockOut, $duty->shift, $duty->duty_date) : 0;

            $log->forceFill([
                'user_id' => $duty->user_id,
                'shift_id' => $duty->shift_id,
                'duty_assignment_id' => $duty->id,
                'log_date' => $duty->duty_date->toDateString(),
                'clock_in_at' => $clockIn,
                'clock_out_at' => $clockOut,
                'late_minutes' => $late,
                'undertime_minutes' => $undertime,
                'overtime_minutes' => $clockOut !== null ? $this->calculator->overtimeMinutes($clockIn, $clockOut, $duty->shift) : 0,
                'status' => TimeLogStatus::fromMinutes($late, $undertime, hasClockedOut: $clockOut !== null),
                'recorded_by' => $actor->id,
                'manual_reason' => trim($reason),
            ])->save();

            $this->audit->record(AuditArea::Attendance, 'attendance.manual', "Recorded {$duty->user->name}'s attendance by hand for {$duty->duty_date->format('M j')}", $log, [
                ...AuditLogger::diff($before, $log->only(['clock_in_at', 'clock_out_at'])),
                'note' => trim($reason),
            ], causer: $actor);

            // Their pay follows this record, so they see what was entered for them, and by whom.
            UpdateNotice::tell($duty->user, $actor, 'Attendance recorded for you: '.$duty->duty_date->format('D, M j'),
                'In '.$clockIn->format('g:i A').($clockOut !== null ? ', out '.$clockOut->format('g:i A') : '').". Entered by hand by {$actor->name}: ".trim($reason).' If it\'s wrong, tell Operations.', route('attendance'), 'clock');

            return $log;
        });
    }
}
