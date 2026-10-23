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
 * @property string|null $generation_status "Generate all" in the background: queued, running, done, failed (null: never started)
 * @property int $generation_total
 * @property int $generation_done
 * @property int $generation_generated
 * @property int $generation_skipped_no_rate
 * @property int|null $generation_by
 * @property CarbonImmutable|null $generation_started_at
 * @property CarbonImmutable|null $generation_finished_at
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
            'generation_total' => 'integer',
            'generation_done' => 'integer',
            'generation_generated' => 'integer',
            'generation_skipped_no_rate' => 'integer',
            'generation_started_at' => 'datetime',
            'generation_finished_at' => 'datetime',
        ];
    }

    /** "Generate all" is waiting for, or being worked on by, the background worker. */
    public function isGenerating(): bool
    {
        return in_array($this->generation_status, ['queued', 'running'], true);
    }

    /** 0–100, for the progress bar. */
    public function generationPercent(): int
    {
        return $this->generation_total > 0 ? (int) min(100, floor($this->generation_done / $this->generation_total * 100)) : 0;
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
