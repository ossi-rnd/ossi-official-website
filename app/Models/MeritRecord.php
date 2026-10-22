<?php

namespace App\Models;

use App\Enums\Ability;
use App\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A merit or demerit given to an employee (roadmap item 21). Points are signed
 * and copied from the type when given. A demerit can be answered by the
 * employee until reply_until. Never deleted: voided with a reason.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $merit_type_id
 * @property string $kind merit | demerit
 * @property string $title
 * @property int $points signed
 * @property string $reason
 * @property CarbonImmutable $occurred_on
 * @property int|null $given_by
 * @property CarbonImmutable|null $reply_until
 * @property string|null $employee_reply
 * @property CarbonImmutable|null $replied_at
 * @property CarbonImmutable|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property CarbonImmutable $created_at
 * @property-read User $user
 * @property-read User|null $giver
 * @property-read User|null $voider
 */
class MeritRecord extends Model
{
    /** Days a demerit stays open for the employee's side (like the 5 days of a Notice to Explain). */
    public const REPLY_DAYS = 5;

    /** The total counts the last 12 months: old mistakes stop counting. */
    public const COUNTS_MONTHS = 12;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'occurred_on' => 'date',
            'reply_until' => 'date',
            'replied_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function giver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'given_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * Not voided.
     *
     * @param  Builder<MeritRecord>  $query
     */
    public function scopeStanding(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    /**
     * Counted in the total: standing, in the last 12 months.
     *
     * @param  Builder<MeritRecord>  $query
     */
    public function scopeCounting(Builder $query, ?CarbonImmutable $today = null): void
    {
        $query->standing()->whereDate('occurred_on', '>', ($today ?? CarbonImmutable::today())->subMonths(self::COUNTS_MONTHS)->toDateString());
    }

    public function isDemerit(): bool
    {
        return $this->kind === MeritType::DEMERIT;
    }

    /** The employee can still add their side. */
    public function awaitsReply(): bool
    {
        return $this->isDemerit() && $this->voided_at === null && $this->employee_reply === null
            && $this->reply_until !== null && ! CarbonImmutable::today()->isAfter($this->reply_until);
    }

    /**
     * Who may give this person a merit or demerit: whoever supervises them.
     * Guards: Operation HQ, their region's Satellite Operation, HR. Office staff:
     * HR. HR staff: the President or Vice President (like their leave and complaints). Never yourself.
     */
    public static function canGive(User $giver, User $employee): bool
    {
        if ($giver->id === $employee->id || ! $giver->can(Ability::GiveMerits->value)) {
            return false;
        }

        if ($giver->hasRole(Role::SuperAdmin)) {
            return true;
        }

        $employee->loadMissing('employeeProfile');

        if ($employee->hasRole(Role::Hr)) {
            return $giver->hasAnyRole(...Role::leadership());
        }

        if ($employee->employeeProfile->is_office_staff ?? true) {
            return $giver->hasRole(Role::Hr);
        }

        return $giver->hasRole(Role::Hr) || $giver->hasRole(Role::OperationsHq)
            || ($giver->hasRole(Role::SatelliteOperations) && $giver->canSeeEmployee($employee));
    }

    /**
     * Whose records a viewer sees in the Merits & Demerits list: the same people
     * they may give one to. HR and Super Admin: everyone but HR staff for HR (the
     * Executive's), Operation HQ: guards, a Satellite: its region's guards.
     *
     * @param  Builder<MeritRecord>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        if ($viewer->hasRole(Role::SuperAdmin)) {
            return;
        }

        $query->where(function (Builder $visible) use ($viewer) {
            $visible->whereRaw('1 = 0');

            if ($viewer->hasRole(Role::Hr)) {
                $visible->orWhereHas('user', fn (Builder $user) => $user->whereKeyNot($viewer->id)->whereDoesntHave('roles', fn (Builder $roles) => $roles->where('role', Role::Hr->value)));
            }

            if ($viewer->hasAnyRole(...Role::leadership())) {
                $visible->orWhereHas('user', fn (Builder $user) => $user->whereHas('roles', fn (Builder $roles) => $roles->where('role', Role::Hr->value)));
            }

            // Guards: all of them for Operation HQ; a Satellite's region only.
            if ($viewer->hasRole(Role::OperationsHq)) {
                $visible->orWhereHas('user.employeeProfile', fn (Builder $profile) => $profile->where('is_office_staff', false));
            } elseif ($viewer->hasRole(Role::SatelliteOperations)) {
                $visible->orWhereHas('user.employeeProfile', fn (Builder $profile) => $profile->where('is_office_staff', false)->visibleTo($viewer));
            }
        });
    }

    /**
     * Who sees a person's merits on their 201 file: whoever may give them one,
     * HR and the President or Vice President. The employee sees their own on My Profile.
     */
    public static function canView(User $viewer, User $employee): bool
    {
        return $viewer->id === $employee->id
            || self::canGive($viewer, $employee)
            || $viewer->hasAnyRole(Role::Hr, Role::SuperAdmin, ...Role::leadership());
    }
}
