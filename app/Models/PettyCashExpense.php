<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment out of a petty cash fund, with its receipt. petty_cash_replenishment_id
 * is set once the custodian asks Accounting to replenish it (and cleared if that
 * request is returned). Nothing is fillable: RecordPettyCashExpense sets every column.
 *
 * @property int $id
 * @property int $petty_cash_fund_id
 * @property CarbonImmutable $spent_on
 * @property string $amount
 * @property string $paid_to
 * @property string $purpose
 * @property string $receipt_path
 * @property string|null $receipt_name
 * @property string $receipt_mime
 * @property int $recorded_by
 * @property int|null $petty_cash_replenishment_id
 * @property CarbonImmutable $created_at
 * @property-read PettyCashFund $fund
 * @property-read PettyCashReplenishment|null $replenishment
 * @property-read User $recorder
 */
class PettyCashExpense extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['spent_on' => 'date', 'amount' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<PettyCashFund, $this>
     */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(PettyCashFund::class, 'petty_cash_fund_id');
    }

    /**
     * @return BelongsTo<PettyCashReplenishment, $this>
     */
    public function replenishment(): BelongsTo
    {
        return $this->belongsTo(PettyCashReplenishment::class, 'petty_cash_replenishment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
