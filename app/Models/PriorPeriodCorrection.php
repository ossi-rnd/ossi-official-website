<?php

namespace App\Models;

use App\Enums\AdjustmentStatus;
use App\Enums\AwayReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pay that changed after a payslip was frozen, suggested on the open run for
 * Accounting to accept (→ a Correction adjustment) or dismiss. See
 * App\Actions\Payroll\DetectPriorPeriodCorrections.
 *
 * @property int $id
 * @property int $user_id
 * @property int $payroll_run_id
 * @property int $from_payroll_run_id
 * @property string $amount
 * @property array<string, array{0: int|float, 1: int|float}> $differences
 * @property string $status
 * @property int|null $payroll_adjustment_id
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $dismiss_reason
 * @property-read User $user
 * @property-read PayrollRun $run
 * @property-read PayrollRun $fromRun
 * @property-read PayrollAdjustment|null $adjustment
 */
class PriorPeriodCorrection extends Model
{
    public const SUGGESTED = 'suggested';

    public const ACCEPTED = 'accepted';

    public const DISMISSED = 'dismissed';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::SUGGESTED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'differences' => 'array',
            'decided_at' => 'datetime',
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
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function fromRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'from_payroll_run_id');
    }

    /**
     * @return BelongsTo<PayrollAdjustment, $this>
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(PayrollAdjustment::class, 'payroll_adjustment_id');
    }

    /**
     * @param  Builder<PriorPeriodCorrection>  $query
     */
    public function scopeSuggested(Builder $query): void
    {
        $query->where('status', self::SUGGESTED);
    }

    /**
     * Already decided, so the next check doesn't suggest it again: dismissed, or
     * accepted with its adjustment still there (removing or rejecting that
     * adjustment makes the difference owed again).
     *
     * @param  Builder<PriorPeriodCorrection>  $query
     */
    public function scopeSettled(Builder $query): void
    {
        $query->where(fn (Builder $settled) => $settled
            ->where('status', self::DISMISSED)
            ->orWhere(fn (Builder $accepted) => $accepted->where('status', self::ACCEPTED)
                ->whereHas('adjustment', fn (Builder $adjustment) => $adjustment->where('status', '!=', AdjustmentStatus::Rejected->value))));
    }

    /**
     * What changed, in words: "1 more day worked, 2 hrs more overtime".
     */
    public function summary(): string
    {
        $parts = [];

        foreach ($this->differences as $what => [$before, $after]) {
            $parts[] = self::describe($what, $before, $after);
        }

        return $parts === [] ? 'recomputed pay differs' : implode(', ', $parts);
    }

    /**
     * The payslip line: "Prior period Oct 1–15, 2026: 1 more day worked".
     */
    public function adjustmentReason(): string
    {
        return "Prior period {$this->fromRun->periodLabel()}: {$this->summary()}";
    }

    private static function describe(string $what, int|float $before, int|float $after): string
    {
        $diff = $after - $before;
        // "2 hrs", "1 hr 30 min": the same wording as time away.
        $minutes = fn (int|float $value): string => AwayReason::durationLabel((int) abs($value));

        return match ($what) {
            'days' => abs($diff).' '.($diff > 0 ? 'more' : 'fewer').' '.str('day')->plural((int) abs($diff)).' worked',
            'overtime_minutes' => $minutes($diff).($diff > 0 ? ' more' : ' less').' overtime',
            'night_minutes' => $minutes($diff).($diff > 0 ? ' more' : ' less').' night work',
            'late_minutes' => $minutes($diff).($diff > 0 ? ' more' : ' less').' late',
            'daily_rate' => 'daily rate ₱'.number_format((float) $before, 2).' → ₱'.number_format((float) $after, 2),
            'holiday_pay' => 'holiday pay ₱'.number_format((float) $before, 2).' → ₱'.number_format((float) $after, 2),
            default => $what.' changed',
        };
    }
}
