<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An employer-set bracket: min ≤ late minutes ≤ max (max null = no upper limit).
 *
 * @property int $id
 * @property int $min_minutes
 * @property int|null $max_minutes
 * @property string $deduction_amount
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['min_minutes', 'max_minutes', 'deduction_amount'])]
class LateDeductionRule extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_minutes' => 'integer',
            'max_minutes' => 'integer',
            'deduction_amount' => 'decimal:2',
        ];
    }

    public function rangeLabel(): string
    {
        return $this->max_minutes === null
            ? "{$this->min_minutes} min and above"
            : "{$this->min_minutes} – {$this->max_minutes} min";
    }
}
