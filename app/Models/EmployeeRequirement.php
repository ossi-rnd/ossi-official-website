<?php

namespace App\Models;

use App\Enums\Requirement;
use App\Enums\RequirementStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One document of a 201 file. Nothing is fillable: every change goes through
 * an Action in App\Actions\People, which also writes the audit entry.
 *
 * @property int $id
 * @property int $employee_profile_id
 * @property Requirement $requirement
 * @property RequirementStatus $status
 * @property string|null $file_path
 * @property string|null $file_name
 * @property string|null $file_mime
 * @property int|null $file_size
 * @property CarbonImmutable|null $expires_on
 * @property CarbonImmutable|null $promised_on
 * @property string|null $note
 * @property int|null $submitted_by
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $verified_by
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read EmployeeProfile $profile
 * @property-read User|null $verifier
 */
class EmployeeRequirement extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'missing',
        'file_path' => null,
        'file_name' => null,
        'file_mime' => null,
        'file_size' => null,
        'expires_on' => null,
        'promised_on' => null,
        'note' => null,
        'submitted_by' => null,
        'submitted_at' => null,
        'verified_by' => null,
        'verified_at' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requirement' => Requirement::class,
            'status' => RequirementStatus::class,
            'file_size' => 'integer',
            'expires_on' => 'date',
            'promised_on' => 'date',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
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
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isExpired(?CarbonImmutable $today = null): bool
    {
        return $this->expires_on !== null && $this->expires_on->lessThan($today ?? CarbonImmutable::today());
    }

    /**
     * Whole days until expiry (0 on the day, negative once expired), or null.
     */
    public function daysToExpiry(?CarbonImmutable $today = null): ?int
    {
        return $this->expires_on !== null
            ? (int) ($today ?? CarbonImmutable::today())->diffInDays($this->expires_on, false)
            : null;
    }

    public function hasFile(): bool
    {
        return $this->file_path !== null;
    }
}
