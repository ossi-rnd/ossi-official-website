<?php

namespace App\Models;

use App\Enums\ReplenishmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The custodian asks Accounting to give back what the fund spent: the expenses in it,
 * and their total. Released: the cash is back. Returned: the expenses go back to the
 * list, to be fixed and asked for again. Nothing is fillable: the Actions set every column.
 *
 * @property int $id
 * @property int $petty_cash_fund_id
 * @property int $requested_by
 * @property string $amount
 * @property ReplenishmentStatus $status
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property CarbonImmutable $created_at
 * @property-read PettyCashFund $fund
 * @property-read User $requester
 * @property-read User|null $decider
 * @property-read Collection<int, PettyCashExpense> $expenses
 */
class PettyCashReplenishment extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'status' => ReplenishmentStatus::class, 'decided_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<PettyCashFund, $this>
     */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(PettyCashFund::class, 'petty_cash_fund_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return HasMany<PettyCashExpense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(PettyCashExpense::class, 'petty_cash_replenishment_id');
    }
}
