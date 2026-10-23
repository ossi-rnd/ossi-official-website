<?php

namespace App\Models;

use App\Enums\EquipmentStatus;
use App\Enums\Role;
use App\Support\Heads;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One item asked for by, or given to, one person: a uniform (charged, with their authority to
 * deduct), a radio from the masterlist, or other company gear (they sign that they're
 * accountable for it). Who issues (the owner's choice, 2026-10-23): Operations for the guards
 * it deploys (a Satellite: its region's), Admin for office staff; the Super Admin as backup.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $equipment_item_id
 * @property int|null $radio_id
 * @property string $label
 * @property string|null $size
 * @property int $quantity
 * @property string|null $unit_price
 * @property bool $charged a uniform: paid by the employee through an authorized deduction
 * @property int|null $employee_deduction_id
 * @property EquipmentStatus $status
 * @property string|null $request_note
 * @property CarbonImmutable|null $requested_at
 * @property string|null $reject_reason
 * @property int|null $issued_by
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $acknowledged_at
 * @property string|null $acknowledgement the sentence they confirmed, word for word
 * @property int|null $returned_to
 * @property CarbonImmutable|null $returned_at
 * @property string|null $return_note
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read EquipmentItem|null $item
 * @property-read Radio|null $radio
 * @property-read User|null $issuer
 * @property-read EmployeeDeduction|null $deduction
 * @property-read Collection<int, EquipmentIncident> $incidents
 */
class EquipmentIssue extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EquipmentStatus::class,
            'unit_price' => 'decimal:2',
            'charged' => 'boolean',
            'requested_at' => 'datetime',
            'issued_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    /**
     * Whether this person may issue to (or take back from, or report an item of) that one.
     * Never to yourself; only to someone still on the payroll.
     */
    public static function canIssueTo(User $issuer, User $employee): bool
    {
        $employee->loadMissing('employeeProfile');

        if ($issuer->is($employee) || $employee->employeeProfile === null || ! $issuer->can('issue-equipment')) {
            return false;
        }

        return match (true) {
            $issuer->hasRole(Role::SuperAdmin) => true,
            ! $employee->employeeProfile->is_office_staff => $issuer->hasRole(Role::OperationsHq) || ($issuer->hasRole(Role::SatelliteOperations) && $issuer->canSeeEmployee($employee)),
            default => $issuer->hasRole(Role::Admin),
        };
    }

    /**
     * Who to tell when this person asks for something: Operations for a guard (Operation HQ
     * and their region's Satellite), Admin for office staff.
     *
     * @return Collection<int, User>
     */
    public static function issuersFor(User $employee): Collection
    {
        $employee->loadMissing('employeeProfile');

        if ($employee->employeeProfile !== null && ! $employee->employeeProfile->is_office_staff) {
            return Heads::of($employee);
        }

        return User::query()->notifiable([Role::Admin])->whereKeyNot($employee->id)->get();
    }

    /**
     * Narrows a query of users to the people this viewer issues to.
     *
     * @param  Builder<User>  $users
     */
    public static function constrainPeopleHandledBy(Builder $users, User $viewer): void
    {
        match (true) {
            $viewer->hasRole(Role::SuperAdmin) => null,
            $viewer->hasRole(Role::OperationsHq) => $users->fieldStaff(),
            $viewer->hasRole(Role::SatelliteOperations) => $users->fieldStaff()->visibleTo($viewer),
            $viewer->hasRole(Role::Admin) => $users->officeStaff(),
            default => $users->whereRaw('1 = 0'),
        };
    }

    /**
     * The items of the people this viewer issues to.
     *
     * @param  Builder<EquipmentIssue>  $query
     */
    public function scopeHandledBy(Builder $query, User $viewer): void
    {
        $query->whereHas('user', fn (Builder $users) => self::constrainPeopleHandledBy($users, $viewer));
    }

    /**
     * Still with the person and theirs to give back: company property issued and not
     * returned or lost (a charged uniform is theirs).
     *
     * @param  Builder<EquipmentIssue>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->where('status', EquipmentStatus::Issued)->where('charged', false);
    }

    public function isCompanyProperty(): bool
    {
        return ! $this->charged;
    }

    public function total(): float
    {
        return round((float) $this->unit_price * $this->quantity, 2);
    }

    /** "Uniform polo (L) × 2" */
    public function describe(): string
    {
        return $this->label.($this->size ? " ({$this->size})" : '').($this->quantity > 1 ? " × {$this->quantity}" : '');
    }

    /** An open damage or loss case: reported, not decided yet. */
    public function openIncident(): ?EquipmentIncident
    {
        return $this->incidents->first(fn (EquipmentIncident $incident) => $incident->decision === null);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<EquipmentItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(EquipmentItem::class, 'equipment_item_id');
    }

    /**
     * @return BelongsTo<Radio, $this>
     */
    public function radio(): BelongsTo
    {
        return $this->belongsTo(Radio::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * @return BelongsTo<EmployeeDeduction, $this>
     */
    public function deduction(): BelongsTo
    {
        return $this->belongsTo(EmployeeDeduction::class, 'employee_deduction_id');
    }

    /**
     * @return HasMany<EquipmentIncident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(EquipmentIncident::class)->orderBy('id');
    }
}
