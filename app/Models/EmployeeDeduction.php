<?php

namespace App\Models;

use App\Enums\DeductionAuthorizationBasis;
use App\Enums\DeductionType;
use App\Support\Acknowledgements;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Loans, cash advances and other per-payslip company deductions.
 *
 * @property int $id
 * @property int $user_id
 * @property string $label
 * @property DeductionType $type
 * @property string $installment_amount
 * @property string|null $original_amount
 * @property string|null $remaining_balance
 * @property bool $is_active
 * @property bool $awaiting_authorization asked in the app and not yet authorized: no payslip takes it
 * @property DeductionAuthorizationBasis|null $authorization_basis null: added before 2026-10-17, or by the system
 * @property string|null $authorization the sentence they authorized, word for word
 * @property CarbonImmutable|null $authorized_at
 * @property CarbonImmutable|null $declined_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 */
#[Fillable(['label', 'type', 'installment_amount', 'original_amount'])]
class EmployeeDeduction extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'awaiting_authorization' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DeductionType::class,
            'installment_amount' => 'decimal:2',
            'original_amount' => 'decimal:2',
            'remaining_balance' => 'decimal:2',
            'is_active' => 'boolean',
            'awaiting_authorization' => 'boolean',
            'authorization_basis' => DeductionAuthorizationBasis::class,
            'authorized_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    /**
     * What the employee authorizes in the app (Labor Code Art. 113), saved word for word.
     */
    public function authorizationText(string $locale = 'en'): string
    {
        $replace = [
            'each' => '₱'.number_format((float) $this->installment_amount, 2),
            'what' => strtolower($this->type->label())." \"{$this->label}\"",
            'total' => '₱'.number_format((float) $this->original_amount, 2),
        ];

        return Acknowledgements::line($this->original_amount !== null ? 'deduction.with_total' : 'deduction.recurring', $locale, $replace);
    }

    /**
     * What payslips take: switched on, and not waiting for the employee's authorization.
     *
     * @param  Builder<EmployeeDeduction>  $query
     */
    public function scopeTakenFromPay(Builder $query): void
    {
        $query->where('is_active', true)->where('awaiting_authorization', false);
    }

    /**
     * @param  Builder<EmployeeDeduction>  $query
     */
    public function scopeAwaitingAuthorization(Builder $query): void
    {
        $query->where('awaiting_authorization', true)->whereNull('declined_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<EmployeeDeduction>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The amount this payslip actually takes: never more than what is still owed.
     */
    public function amountDue(): float
    {
        $installment = (float) $this->installment_amount;

        return $this->remaining_balance === null
            ? $installment
            : min($installment, (float) $this->remaining_balance);
    }
}
