<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of someone's cash bond that a payslip doesn't show: the balance
 * paid in before the system (opening), a correction (adjustment), or the
 * refund in final pay (negative). The ₱100 a cut-off is a deduction line on
 * each payslip instead (CashBondBalanceQuery adds both up). Never edited:
 * a mistake is corrected with another entry. Nothing is fillable.
 *
 * @property int $id
 * @property int $user_id
 * @property string $kind opening | adjustment | refund
 * @property string $amount + paid in, − paid out
 * @property string|null $note
 * @property int|null $recorded_by
 * @property int|null $final_pay_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read User|null $recorder
 */
class CashBondEntry extends Model
{
    public const string OPENING = 'opening';

    public const string ADJUSTMENT = 'adjustment';

    public const string REFUND = 'refund';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
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
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
