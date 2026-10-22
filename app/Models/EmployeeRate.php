<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One daily rate and the date it starts. Written only by SetEmployeeRate.
 *
 * @property int $id
 * @property int $employee_profile_id
 * @property string $daily_rate
 * @property CarbonImmutable $effective_from
 * @property string|null $note
 * @property int|null $set_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read EmployeeProfile $profile
 * @property-read User|null $setter
 */
class EmployeeRate extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'note' => null,
        'set_by' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'daily_rate' => 'decimal:2',
            'effective_from' => 'date',
        ];
    }

    /**
     * @return BelongsTo<EmployeeProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
