<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A version of the SSS / PhilHealth / Pag-IBIG / BIR rules, from a date.
 * Written only by App\Actions\Payroll\SaveStatutoryRuleSet.
 *
 * @property int $id
 * @property CarbonImmutable $effective_from
 * @property array{sss: array<string, int|float>, philhealth: array<string, int|float>, pagibig: array<string, int|float>, withholding_semi_monthly: list<array{over: int|float, base: int|float, rate: int|float}>} $rules
 * @property string|null $note
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $creator
 */
class StatutoryRuleSet extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'note' => null,
        'created_by' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'rules' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
