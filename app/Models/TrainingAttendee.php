<?php

namespace App\Models;

use App\Enums\TrainingAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $training_id
 * @property int $employee_profile_id
 * @property TrainingAttendance $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Training $training
 * @property-read EmployeeProfile $profile
 */
class TrainingAttendee extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'enrolled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => TrainingAttendance::class];
    }

    /**
     * @return BelongsTo<Training, $this>
     */
    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    /**
     * @return BelongsTo<EmployeeProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id');
    }
}
