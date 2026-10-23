<?php

namespace App\Models;

use App\Enums\Ability;
use App\Enums\EmploymentStatus;
use App\Enums\Position;
use App\Enums\Region;
use App\Enums\Requirement;
use App\Enums\Role;
use App\Support\EmployeeNumber;
use Carbon\CarbonImmutable;
use Database\Factories\EmployeeProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The HR record of an employee. Exists before the login account does.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $shift_id
 * @property int|null $client_id
 * @property int|null $office_id the OSSI office an office staffer works at (guards have a client instead)
 * @property string $employee_number
 * @property string $name
 * @property string $email
 * @property string|null $detachment
 * @property string|null $post
 * @property Position|null $position
 * @property CarbonImmutable|null $hire_date
 * @property EmploymentStatus $employment_status
 * @property string|null $contact_number
 * @property string|null $permanent_address
 * @property string|null $birth_date "Y-m-d", encrypted (it reveals age); read it with birthDate()
 * @property bool $birthday_private they objected to the birthday reminder: nobody is told
 * @property string|null $sss_number
 * @property string|null $philhealth_number
 * @property string|null $pagibig_number
 * @property string|null $tin_number
 * @property list<array{label: string, number: string}>|null $other_ids other IDs (driver's license, passport…), encrypted
 * @property string|null $medical_conditions
 * @property string|null $blood_type
 * @property string|null $emergency_contact_name
 * @property string|null $emergency_contact_phone
 * @property bool $is_office_staff
 * @property bool $is_test_account
 * @property Role|null $pending_role set in advance on Roles & Access; given to the account when they register
 * @property Region|null $pending_region with a pending Satellite Operation role
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $user
 * @property-read Shift|null $shift
 * @property-read Client|null $client
 * @property-read Office|null $office
 * @property-read Collection<int, EmployeeRequirement> $requirements
 * @property-read Collection<int, EmployeeRate> $rates
 * @property-read int|null $requirements_on_file_count
 */
#[Fillable([
    'employee_number', 'name', 'email', 'client_id', 'office_id', 'detachment', 'post', 'position', 'hire_date', 'employment_status',
    'contact_number', 'permanent_address', 'birth_date', 'birthday_private',
    'sss_number', 'philhealth_number', 'pagibig_number', 'tin_number', 'other_ids',
    'medical_conditions', 'blood_type', 'emergency_contact_name', 'emergency_contact_phone',
    'is_office_staff', 'is_test_account',
])]
class EmployeeProfile extends Model
{
    /** @use HasFactory<EmployeeProfileFactory> */
    use HasFactory;

    /**
     * Defaults the app reads right after create() (the DB default is not read back).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'employment_status' => 'probationary',
        'is_office_staff' => false,
        'is_test_account' => false,
        'office_id' => null,
        'birthday_private' => false,
    ];

    /**
     * A test account's email domain: reserved (RFC 2606), so nothing sent there is
     * ever delivered, and User::routeNotificationForMail() doesn't even try.
     */
    public const TEST_EMAIL_DOMAIN = 'test.invalid';

    /**
     * Fields shown in the audit log as "changed", never with their values.
     *
     * @var list<string>
     */
    public const SENSITIVE = ['birth_date', 'sss_number', 'philhealth_number', 'pagibig_number', 'tin_number', 'other_ids', 'medical_conditions'];

    /** Suggested names for other IDs (any name can be typed). */
    public const OTHER_ID_SUGGESTIONS = [
        'Security Guard License (LESP)', "Driver's License", 'Passport', 'UMID', 'PhilSys National ID',
        'PRC License', 'Postal ID', "Voter's ID", 'NBI Clearance', 'Police Clearance', 'Barangay Clearance',
    ];

    /** At most this many other IDs on one record. */
    public const MAX_OTHER_IDS = 10;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => Position::class,
            'hire_date' => 'date',
            'employment_status' => EmploymentStatus::class,
            // Encrypted at rest with APP_KEY: a leaked database dump doesn't leak government IDs.
            'sss_number' => 'encrypted',
            'philhealth_number' => 'encrypted',
            'pagibig_number' => 'encrypted',
            'tin_number' => 'encrypted',
            'other_ids' => 'encrypted:array',
            'medical_conditions' => 'encrypted',
            // Age is sensitive (RA 10173 sec. 3(l)): encrypted like the IDs.
            'birth_date' => 'encrypted',
            'birthday_private' => 'boolean',
            'pending_role' => Role::class,
            'pending_region' => Region::class,
            'is_office_staff' => 'boolean',
            'is_test_account' => 'boolean',
        ];
    }

    public function birthDate(): ?CarbonImmutable
    {
        return filled($this->birth_date) ? CarbonImmutable::parse($this->birth_date) : null;
    }

    /**
     * Their birthday in that year; Feb 29 falls on Feb 28 in a common year. Null without a date of birth.
     */
    public function birthdayIn(int $year): ?CarbonImmutable
    {
        $born = $this->birthDate();

        if ($born === null) {
            return null;
        }

        $day = $born->month === 2 && $born->day === 29 && ! checkdate(2, 29, $year) ? 28 : $born->day;

        return CarbonImmutable::create($year, $born->month, $day);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Records this viewer may see: all, or for a regional account only those
     * deployed to a client in their region (none until a region is set).
     *
     * @param  Builder<EmployeeProfile>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        if (! $viewer->isRegionallyLimited()) {
            // Operation HQ (no view-office-staff) looks after guards, and heads the Satellite Operations.
            if (! $viewer->can(Ability::ViewOfficeStaff->value)) {
                $query->where(fn (Builder $who) => $who->where('is_office_staff', false)
                    ->when($viewer->hasRole(Role::OperationsHq), fn (Builder $who) => $who->orWhereHas('user.roles', fn (Builder $roles) => $roles->where('role', Role::SatelliteOperations->value))));
            }

            return;
        }

        $viewer->operations_region === null
            ? $query->whereRaw('1 = 0')
            : $query->whereHas('client', fn (Builder $client) => $client->where('region', $viewer->operations_region->value));
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return HasMany<TrainingAttendee, $this>
     */
    public function trainingAttendances(): HasMany
    {
        return $this->hasMany(TrainingAttendee::class);
    }

    /**
     * The client site they're deployed to; `detachment` holds its name.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The OSSI office an office staffer works at: where their clock-in must be.
     *
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * Employees who appear on payroll: an account exists, they still work
     * here, and they aren't paid outside it (User::scopeOnPayroll's rule).
     *
     * @param  Builder<EmployeeProfile>  $query
     */
    public function scopeOnPayroll(Builder $query): void
    {
        $query->whereNotNull('user_id')->paidHere();
    }

    /**
     * Still working here, with or without an account yet.
     *
     * @param  Builder<EmployeeProfile>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('employment_status', EmploymentStatus::active());
    }

    /**
     * When probation ended, for someone still marked Probationary past it; null
     * otherwise. Labor Code Art. 296: allowed to keep working, they're regular by
     * law, so the Employees list and HR's dashboard flag it until HR decides.
     */
    public function probationOverdueSince(): ?CarbonImmutable
    {
        if ($this->employment_status !== EmploymentStatus::Probationary || $this->hire_date === null) {
            return null;
        }

        $endsOn = CarbonImmutable::parse($this->hire_date)->addMonthsNoOverflow((int) config('hr.probation.months'));

        return $endsOn->isBefore(CarbonImmutable::today()) ? $endsOn : null;
    }

    /**
     * Probationary, and past the probation months (ended before today).
     *
     * @param  Builder<EmployeeProfile>  $query
     */
    public function scopeProbationOverdue(Builder $query): void
    {
        $query->where('employment_status', EmploymentStatus::Probationary)
            ->whereNotNull('hire_date')
            ->whereDate('hire_date', '<', CarbonImmutable::today()->subMonthsNoOverflow((int) config('hr.probation.months'))->toDateString());
    }

    /**
     * Still working here (with or without an account yet) and paid through
     * this payroll, so not, e.g., the President or Vice President: the records that need a daily rate.
     *
     * @param  Builder<EmployeeProfile>  $query
     */
    public function scopePaidHere(Builder $query): void
    {
        $query->active()->where(fn (Builder $paid) => $paid->whereNull('user_id')
            ->orWhereDoesntHave('user.roles', fn (Builder $roles) => $roles->whereIn('role', array_map(fn (Role $role) => $role->value, Role::paidOutsidePayroll()))));
    }

    /**
     * Neither a personal rate nor a rate for their position in force today:
     * payroll pays them the schedule's company default until Accounting sets
     * one (Daily Rates). Mirrors EffectiveRatesQuery.
     *
     * @param  Builder<EmployeeProfile>  $query
     */
    public function scopeWithoutRate(Builder $query): void
    {
        $today = today()->toDateString();

        $query->whereDoesntHave('rates', fn (Builder $rates) => $rates->whereDate('effective_from', '<=', $today))
            ->where(fn (Builder $noPositionRate) => $noPositionRate
                ->whereNull('position')
                ->orWhereNotExists(fn (\Illuminate\Database\Query\Builder $rate) => $rate->selectRaw('1')
                    ->from('position_rates')
                    ->whereColumn('position_rates.position', 'employee_profiles.position')
                    ->whereDate('position_rates.effective_from', '<=', $today)));
    }

    /**
     * @return HasMany<EmployeeRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(EmployeeRate::class);
    }

    /**
     * @return HasMany<EmployeeRequirement, $this>
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(EmployeeRequirement::class);
    }

    public function isClaimed(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * A test account's email: the employee number, capitals kept (the province code reads as
     * on the ID card, -RH too), at test.invalid: 260627PAM-0950@test.invalid. The account keeps
     * the same capitals; registering and signing in match the email in any case.
     */
    public static function testEmailFor(string $employeeNumber): string
    {
        return EmployeeNumber::normalize($employeeNumber).'@'.self::TEST_EMAIL_DOMAIN;
    }

    public static function isTestEmail(string $email): bool
    {
        return str_ends_with(strtolower($email), '@'.self::TEST_EMAIL_DOMAIN);
    }

    /**
     * Detachment and post describe where a GUARD is deployed. Office staff
     * (HR, Admin, Accounting…) have neither, so screens ask this instead of
     * showing two empty "—" fields.
     */
    public function hasDeployment(): bool
    {
        return ! $this->is_office_staff;
    }

    /**
     * "SM Megamall · Main Entrance" for a guard, "Office staff" for the office.
     */
    public function assignmentLabel(): string
    {
        if (! $this->hasDeployment()) {
            return 'Office staff';
        }

        return collect([$this->detachment, $this->post])->filter()->implode(' · ') ?: '—';
    }

    /**
     * The roster's Assignment column: where a guard is posted, what an office
     * employee does ("Accounting", "HR Staff"); "Office staff" if no position yet.
     */
    public function rosterAssignmentLabel(): string
    {
        if (! $this->hasDeployment()) {
            return $this->position?->label() ?? 'Office staff';
        }

        return $this->assignmentLabel();
    }

    /**
     * @return list<Requirement>
     */
    public function applicableRequirements(): array
    {
        return Requirement::for($this->is_office_staff);
    }
}
