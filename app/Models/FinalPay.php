<?php

namespace App\Models;

use App\Enums\Ability;
use App\Enums\FinalPayStatus;
use App\Enums\ResignationStatus;
use App\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The final-pay statement of someone who resigned: computed lines (frozen once
 * released), Accounting's adjustments, the totals, and the sign-offs.
 * Nothing is fillable: the Actions set every column.
 *
 * A line: {kind, label, amount, note}. kind is "pay" (added), "deduct"
 * (taken off) or "info" (shown, not counted: e.g. the last salary, paid in
 * the regular payroll so it isn't paid twice).
 *
 * @property int $id
 * @property int $resignation_id
 * @property int $user_id
 * @property FinalPayStatus $status
 * @property list<array{kind: string, label: string, amount: float, note: string|null, deduction_id?: int}>|null $lines
 * @property list<array{label: string, amount: float, reason: string, by: string}>|null $adjustments
 * @property string $total_pay
 * @property string $total_deducted
 * @property string $net
 * @property string $receivable
 * @property int|null $thirteenth_year
 * @property string $thirteenth_amount
 * @property string $cash_bond_refund
 * @property array<string, array{by: string, by_id: int, at: string}>|null $signoffs
 * @property int|null $prepared_by
 * @property CarbonImmutable|null $prepared_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $released_at
 * @property string|null $return_note
 * @property int|null $returned_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Resignation $resignation
 * @property-read User $user
 * @property-read User|null $preparer
 */
class FinalPay extends Model
{
    /**
     * Who signs, in the order shown: Accounting, then the leadership (the President or
     * the Vice President, either one; the signoff is kept under "president"), whose
     * signature releases it. Only these see the amounts (company decision, 2026-10-16);
     * HR, Operation HQ and Admin confirm their part on the resignation's clearance instead.
     *
     * @var list<Role>
     */
    public const array SIGNERS = [Role::Accounting, Role::President];

    /** The step's name on screens: "Accounting", "President or Vice President". */
    public static function signerLabel(Role $step): string
    {
        return $step === Role::President ? 'President or Vice President' : $step->label();
    }

    /** May this person sign this step? The leadership step: the President or the Vice President. */
    public static function holdsStep(User $user, Role $step): bool
    {
        return $step === Role::President ? $user->hasAnyRole(...Role::leadership()) : $user->hasRole($step);
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FinalPayStatus::class,
            'lines' => 'array',
            'adjustments' => 'array',
            'total_pay' => 'decimal:2',
            'total_deducted' => 'decimal:2',
            'net' => 'decimal:2',
            'receivable' => 'decimal:2',
            'thirteenth_amount' => 'decimal:2',
            'cash_bond_refund' => 'decimal:2',
            'signoffs' => 'array',
            'prepared_at' => 'datetime',
            'submitted_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Resignation, $this>
     */
    public function resignation(): BelongsTo
    {
        return $this->belongsTo(Resignation::class);
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
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function hasSigned(Role $role): bool
    {
        return isset(($this->signoffs ?? [])[$role->value]);
    }

    /**
     * Roles still to sign, in order.
     *
     * @return list<Role>
     */
    public function pendingSigners(): array
    {
        return array_values(array_filter(self::SIGNERS, fn (Role $role) => ! $this->hasSigned($role)));
    }

    /** The President or Vice President signs once everyone before them has. */
    public function readyForLeadership(): bool
    {
        return $this->pendingSigners() === [Role::President];
    }

    /**
     * What waits for this person (the menu badge): statements it's their turn to
     * sign, and for Accounting resignations past their last day with no statement
     * or one still being prepared.
     */
    public static function waitingFor(User $user): int
    {
        $toSign = self::query()->where('status', FinalPayStatus::Signing)->get()
            ->filter(fn (self $finalPay) => $finalPay->signableRoleFor($user) !== null)->count();

        if (! $user->can(Ability::PrepareFinalPay->value)) {
            return $toSign;
        }

        $toPrepare = Resignation::query()
            ->whereIn('status', [ResignationStatus::Accepted, ResignationStatus::Completed])
            ->whereRaw('coalesce(last_day, requested_last_day) <= ?', [CarbonImmutable::today()->toDateString()])
            ->where(fn ($query) => $query->whereDoesntHave('finalPay')->orWhereHas('finalPay', fn ($pay) => $pay->where('status', FinalPayStatus::Draft)))
            ->count();

        return $toSign + $toPrepare;
    }

    /**
     * The step this person would sign now: one they hold that hasn't signed and may
     * sign at this point (the President or Vice President only last). The person who
     * filed the resignation never signs their own.
     */
    public function signableRoleFor(User $user): ?Role
    {
        if ($this->status !== FinalPayStatus::Signing || $user->id === $this->user_id) {
            return null;
        }

        foreach ($this->pendingSigners() as $role) {
            if (self::holdsStep($user, $role) && ($role !== Role::President || $this->readyForLeadership())) {
                return $role;
            }
        }

        return null;
    }
}
