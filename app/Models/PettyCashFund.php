<?php

namespace App\Models;

use App\Enums\Ability;
use App\Enums\ReplenishmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A petty cash fund, kept the imprest way: a fixed amount in the custodian's hands.
 * Each expense is logged with its receipt; once Accounting releases a replenishment,
 * the cash spent is back and the fund is whole again.
 *
 *   cash on hand = amount − expenses not yet replenished (waiting, or in a request still pending)
 *
 * Nothing is fillable: the Actions set every column.
 *
 * @property int $id
 * @property string $name
 * @property int|null $office_id
 * @property int $custodian_id
 * @property string $amount
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Office|null $office
 * @property-read User $custodian
 * @property-read Collection<int, PettyCashExpense> $expenses
 * @property-read Collection<int, PettyCashReplenishment> $replenishments
 */
class PettyCashFund extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'office_id' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    /**
     * @return HasMany<PettyCashExpense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(PettyCashExpense::class);
    }

    /**
     * @return HasMany<PettyCashReplenishment, $this>
     */
    public function replenishments(): HasMany
    {
        return $this->hasMany(PettyCashReplenishment::class);
    }

    /**
     * Expenses whose cash hasn't come back yet: not in a request, or in one still waiting.
     *
     * @return Builder<PettyCashExpense>
     */
    public function outstandingExpenses(): Builder
    {
        return PettyCashExpense::query()->where('petty_cash_fund_id', $this->id)
            ->where(fn (Builder $open) => $open->whereNull('petty_cash_replenishment_id')
                ->orWhereHas('replenishment', fn (Builder $request) => $request->where('status', ReplenishmentStatus::Pending)));
    }

    public function spent(): float
    {
        return round((float) $this->outstandingExpenses()->sum('amount'), 2);
    }

    public function cashOnHand(): float
    {
        return round((float) $this->amount - $this->spent(), 2);
    }

    public function pendingReplenishment(): ?PettyCashReplenishment
    {
        return $this->replenishments()->where('status', ReplenishmentStatus::Pending)->first();
    }

    /** The active fund this person keeps, if any. */
    public static function custodianOf(User $user): ?self
    {
        return self::query()->where('custodian_id', $user->id)->where('is_active', true)->first();
    }

    /** Who opens the Petty Cash page: those who view every fund, and a fund's custodian. */
    public static function canOpenPage(User $user): bool
    {
        return $user->can(Ability::ViewPettyCash->value) || self::custodianOf($user) !== null;
    }

    /** Who may look at this fund (and its receipts). */
    public function canBeSeenBy(User $user): bool
    {
        return $user->can(Ability::ViewPettyCash->value) || $this->custodian_id === $user->id;
    }
}
