<?php

namespace App\Models;

use App\Enums\Ability;
use App\Enums\EmploymentStatus;
use App\Enums\Region;
use App\Enums\Role;
use App\Notifications\QueuedResetPassword;
use App\Notifications\QueuedVerifyEmail;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $avatar_path
 * @property Region|null $operations_region
 * @property string|null $privacy_notice_version
 * @property CarbonImmutable|null $privacy_acknowledged_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, UserRole> $roles
 * @property-read EmployeeProfile|null $employeeProfile
 * @property-read UserDevice|null $device
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Nullable columns the app reads right after create(). Laravel does not
     * read the row back, so without these, strict mode throws on access.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'avatar_path' => null,
        'operations_region' => null,
        'privacy_notice_version' => null,
        'privacy_acknowledged_at' => null,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'privacy_acknowledged_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'operations_region' => Region::class,
        ];
    }

    /** No mail to a test account's address (test.invalid never delivers): the in-app notice still shows. */
    public function routeNotificationForMail(): ?string
    {
        return EmployeeProfile::isTestEmail($this->email) ? null : $this->email;
    }

    /**
     * @return HasMany<UserRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * @return HasOne<EmployeeProfile, $this>
     */
    public function employeeProfile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    /**
     * The phone they clock in with (registered by their first clock-in).
     *
     * @return HasOne<UserDevice, $this>
     */
    public function device(): HasOne
    {
        return $this->hasOne(UserDevice::class);
    }

    /**
     * @return HasMany<TimeLog, $this>
     */
    public function timeLogs(): HasMany
    {
        return $this->hasMany(TimeLog::class);
    }

    /**
     * @return HasMany<DutyAssignment, $this>
     */
    public function dutyAssignments(): HasMany
    {
        return $this->hasMany(DutyAssignment::class);
    }

    /**
     * @return HasMany<LeaveRequest, $this>
     */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * @return HasMany<OvertimeRequest, $this>
     */
    public function overtimeRequests(): HasMany
    {
        return $this->hasMany(OvertimeRequest::class);
    }

    /**
     * @return HasMany<Resignation, $this>
     */
    public function resignations(): HasMany
    {
        return $this->hasMany(Resignation::class);
    }

    /**
     * @return HasMany<EmployeeDeduction, $this>
     */
    public function employeeDeductions(): HasMany
    {
        return $this->hasMany(EmployeeDeduction::class);
    }

    /**
     * @return HasMany<PayslipRecord, $this>
     */
    public function payslipRecords(): HasMany
    {
        return $this->hasMany(PayslipRecord::class);
    }

    /**
     * @return HasMany<PayrollAdjustment, $this>
     */
    public function payrollAdjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }

    /**
     * @return HasMany<MeritRecord, $this>
     */
    public function meritRecords(): HasMany
    {
        return $this->hasMany(MeritRecord::class);
    }

    /**
     * @return HasMany<PriorPeriodCorrection, $this>
     */
    public function priorPeriodCorrections(): HasMany
    {
        return $this->hasMany(PriorPeriodCorrection::class);
    }

    /**
     * @return HasMany<PaidLeavePayment, $this>
     */
    public function paidLeavePayments(): HasMany
    {
        return $this->hasMany(PaidLeavePayment::class);
    }

    /**
     * Due a payslip on this run: they worked in the period, or they have accepted
     * paid leave on it (someone on paternity leave the whole cut-off is still paid).
     *
     * @param  Builder<User>  $query
     */
    public function scopeDueAPayslip(Builder $query, PayrollRun $run): void
    {
        $query->where(fn (Builder $due) => $due
            ->whereHas('timeLogs', fn ($logs) => $logs->between($run->start_date, $run->end_date))
            ->orWhereHas('paidLeavePayments', fn ($leave) => $leave->where('payroll_run_id', $run->id)->where('status', PaidLeavePayment::ACCEPTED)));
    }

    /**
     * Employees on payroll: they have an HR profile and still work here.
     * Office staff are included; their profile flag gives them office rates.
     *
     * @param  Builder<User>  $query
     */
    public function scopeOnPayroll(Builder $query): void
    {
        $query->whereHas('employeeProfile', fn (Builder $profile) => $profile->whereIn('employment_status', EmploymentStatus::active()))
            ->whereDoesntHave('roles', fn (Builder $roles) => $roles->whereIn('role', array_map(fn (Role $role) => $role->value, Role::paidOutsidePayroll())));
    }

    /** Paid outside this payroll (e.g. the President or Vice President): no payslips or attendance here. */
    public function isPaidOutsidePayroll(): bool
    {
        return $this->hasAnyRole(...Role::paidOutsidePayroll());
    }

    /**
     * Office staff: their leave, overtime and time away go to the President or Vice President.
     * No HR profile (e.g. a Super Admin's own account) counts as office staff.
     */
    public function isOfficeStaff(): bool
    {
        $this->loadMissing('employeeProfile');

        return $this->employeeProfile->is_office_staff ?? true;
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeOfficeStaff(Builder $query): void
    {
        $query->where(fn (Builder $who) => $who->whereDoesntHave('employeeProfile')
            ->orWhereHas('employeeProfile', fn (Builder $profile) => $profile->where('is_office_staff', true)));
    }

    /**
     * Guards and other field staff.
     *
     * @param  Builder<User>  $query
     */
    public function scopeFieldStaff(Builder $query): void
    {
        $query->whereHas('employeeProfile', fn (Builder $profile) => $profile->where('is_office_staff', false));
    }

    /**
     * Employees this viewer may see: everyone; for a regional account (Satellite
     * Operation) only guards deployed to clients in their region; and without
     * view-office-staff (Operation HQ) guards only, since Operations looks after guards.
     *
     * @param  Builder<User>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        if ($viewer->isRegionallyLimited()) {
            $query->whereHas('employeeProfile', fn (Builder $profile) => $profile->visibleTo($viewer));
        } elseif (! $viewer->can(Ability::ViewOfficeStaff->value)) {
            // Guards, and for Operation HQ also the Satellite Operations it heads (Heads).
            $query->where(fn (Builder $who) => $who->whereHas('employeeProfile', fn (Builder $profile) => $profile->where('is_office_staff', false))
                ->when($viewer->hasRole(Role::OperationsHq), fn (Builder $who) => $who->orWhereHas('roles', fn (Builder $roles) => $roles->where('role', Role::SatelliteOperations->value))));
        }
    }

    /**
     * Who the viewer's dashboard counts. Without view-office-staff (Operation HQ and
     * Satellite Operation alike): every guard, in every region, and no office staff;
     * a Satellite's other pages stay limited to its region. Everyone else: whom they
     * may see (visibleTo). One rule for the counts, the charts and the DTR.
     *
     * @param  Builder<User>  $query
     */
    public function scopeOnDashboardOf(Builder $query, User $viewer): void
    {
        if (! $viewer->can(Ability::ViewOfficeStaff->value)) {
            $query->fieldStaff();

            return;
        }

        $query->visibleTo($viewer);
    }

    /**
     * True when none of this user's roles shows the whole company, so they
     * see only their operations_region (and no one, until it's set).
     */
    public function isRegionallyLimited(): bool
    {
        return ! collect($this->roleList())->contains(fn (Role $role) => $role->seesWholeCompany());
    }

    /**
     * The single-record form of visibleTo(), for a page or an Action about one person.
     */
    public function canSeeProfile(?EmployeeProfile $profile): bool
    {
        if (! $this->isRegionallyLimited()) {
            // Operation HQ (no view-office-staff): guards, and the Satellite Operations it heads.
            return $profile === null || ! $profile->is_office_staff || $this->can(Ability::ViewOfficeStaff->value)
                || ($this->hasRole(Role::OperationsHq) && $profile->user_id !== null && UserRole::query()->where('user_id', $profile->user_id)->where('role', Role::SatelliteOperations)->exists());
        }

        $profile?->loadMissing('client');

        return $this->operations_region !== null && $profile?->client?->region === $this->operations_region;
    }

    public function canSeeEmployee(User $employee): bool
    {
        return $this->canSeeProfile($employee->employeeProfile()->first());
    }

    /**
     * Who to notify: holders of any of these roles who still work here
     * (no HR profile, like a Super Admin, counts as still working here).
     *
     * @param  Builder<User>  $query
     * @param  list<Role>  $roles
     */
    public function scopeNotifiable(Builder $query, array $roles): void
    {
        $query->whereHas('roles', fn (Builder $userRoles) => $userRoles->whereIn('role', array_map(fn (Role $role) => $role->value, $roles)))
            ->whereDoesntHave('employeeProfile', fn (Builder $profile) => $profile->whereNotIn('employment_status', EmploymentStatus::active()));
    }

    /**
     * Roles as enums, loaded once per request. loadMissing() is an explicit
     * load, so it doesn't trip preventLazyLoading().
     *
     * @return list<Role>
     */
    public function roleList(): array
    {
        $this->loadMissing('roles');

        return array_values($this->roles
            ->map(fn (UserRole $userRole): Role => $userRole->role)
            ->sortBy(fn (Role $role): int|false => array_search($role, Role::cases(), true))
            ->all());
    }

    public function hasRole(Role $role): bool
    {
        return in_array($role, $this->roleList(), true);
    }

    public function hasAnyRole(Role ...$roles): bool
    {
        return array_intersect(
            array_map(fn (Role $role) => $role->value, $roles),
            array_map(fn (Role $role) => $role->value, $this->roleList()),
        ) !== [];
    }

    /**
     * The highest-privilege role, shown under the name in the top bar.
     */
    public function primaryRole(): ?Role
    {
        return $this->roleList()[0] ?? null;
    }

    /**
     * Compares VERSIONS, not "has ever acknowledged": a new notice version re-prompts everyone.
     */
    public function hasAcknowledgedPrivacyNotice(): bool
    {
        return $this->privacy_acknowledged_at !== null
            && $this->privacy_notice_version === config('privacy.version');
    }

    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path !== null
            ? route('avatars.show', ['user' => $this->id, 'v' => hash('xxh3', $this->avatar_path)])
            : null;
    }

    /**
     * Mail goes through the queue so a slow SMTP server never blocks a request.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerifyEmail);
    }

    /**
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new QueuedResetPassword($token));
    }
}
