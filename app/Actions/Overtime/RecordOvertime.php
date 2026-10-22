<?php

namespace App\Actions\Overtime;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\RequestStatus;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Notifications\RequestDecided;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operations adds a guard's extra time themselves: the guard who held the post
 * longer, a reliever kept on. It's saved approved (they decide guards' overtime
 * anyway), filed_by them, and the guard is told. Payroll still pays the smaller
 * of this and what the guard actually clocked, so it can't pay hours not worked.
 */
final class RecordOvertime
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $recorder, User $guard, CarbonImmutable $date, int $minutes, string $reason): OvertimeRequest
    {
        $guard->loadMissing('employeeProfile');

        $refusal = match (true) {
            ! $recorder->can(Ability::ReviewOvertime->value) => 'Only Operations, HR or Admin add a guard\'s extra time.',
            $guard->id === $recorder->id => 'You cannot add extra time for yourself.',
            $guard->isOfficeStaff() => 'Office staff\'s overtime is approved by the President or Vice President: they ask for it on My Overtime.',
            ! $recorder->canSeeEmployee($guard) => 'This guard is outside your region.',
            $date->isAfter(today()) => 'Extra time is added once it\'s worked: choose today or an earlier date.',
            $minutes < 15 || $minutes > 12 * 60 => 'Extra time is from 15 minutes to 12 hours.',
            default => null,
        };

        if ($refusal !== null) {
            throw ValidationException::withMessages(['extra' => $refusal]);
        }

        return DB::transaction(function () use ($recorder, $guard, $date, $minutes, $reason) {
            // One per day, as when the guard asks: a request they already filed is reviewed instead.
            $existing = $guard->overtimeRequests()->whereDate('date', $date->toDateString())->whereIn('status', RequestStatus::blocking())->lockForUpdate()->first();

            if ($existing !== null) {
                throw ValidationException::withMessages(['extra' => $existing->status === RequestStatus::Pending
                    ? "{$guard->name} already asked for overtime on {$date->format('M j')}: review that request instead."
                    : "{$guard->name} already has approved overtime on {$date->format('M j')}."]);
            }

            $overtime = new OvertimeRequest([
                'date' => $date->toDateString(),
                'post' => $guard->employeeProfile?->assignmentLabel(),
                'requested_minutes' => $minutes,
                'reason' => $reason,
            ]);
            $overtime->user()->associate($guard);
            $overtime->forceFill([
                'filed_by' => $recorder->id,
                'status' => RequestStatus::Approved,
                'approved_minutes' => $minutes,
                'reviewed_by' => $recorder->id,
                'reviewed_at' => now(),
            ])->save();

            $this->audit->record(AuditArea::Overtime, 'overtime.recorded', "Added {$guard->name}'s extra time on {$date->format('M j, Y')}", $overtime, [
                'new' => ['status' => RequestStatus::Approved->value, 'approved_minutes' => $minutes, 'reason' => $reason],
            ], causer: $recorder);

            $guard->notify(new RequestDecided($overtime->setRelation('reviewer', $recorder)));

            return $overtime;
        });
    }
}
