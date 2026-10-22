<?php

namespace App\Models;

use App\Enums\PayrollRunStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property CarbonImmutable $pay_date
 * @property PayrollRunStatus $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read int|null $payslip_records_count from withCount()
 * @property-read string|null $total_gross from withSum()
 * @property-read string|null $total_net from withSum()
 */
#[Fillable(['start_date', 'end_date', 'pay_date'])]
class PayrollRun extends Model
{
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
            'start_date' => 'date',
            'end_date' => 'date',
            'pay_date' => 'date',
            'status' => PayrollRunStatus::class,
        ];
    }

    /**
     * @return HasMany<PayslipRecord, $this>
     */
    public function payslipRecords(): HasMany
    {
        return $this->hasMany(PayslipRecord::class);
    }

    /**
     * @param  Builder<PayrollRun>  $query
     */
    public function scopeVisibleToEmployees(Builder $query): void
    {
        $query->where('status', '!=', PayrollRunStatus::Draft);
    }

    public function periodLabel(): string
    {
        return $this->start_date->isSameMonth($this->end_date)
            ? $this->start_date->format('F j').'–'.$this->end_date->format('j, Y')
            : $this->start_date->format('M j').' – '.$this->end_date->format('M j, Y');
    }
}
