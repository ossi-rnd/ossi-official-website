<?php

namespace App\Models;

use App\Enums\Ability;
use App\Enums\RequestStatus;
use App\Models\Concerns\RoutedByStaffKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $filed_by Operations, HR or Admin, when they added the guard's extra time (RecordOvertime); null when the employee asked
 * @property CarbonImmutable $date
 * @property string|null $post
 * @property int $requested_minutes
 * @property int|null $approved_minutes
 * @property string $reason
 * @property int|null $supervisor_id who it was sent to, on requests before 2026-10-17; now it goes to Operations or the President or Vice President
 * @property RequestStatus $status
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $review_note
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read User|null $supervisor
 * @property-read User|null $reviewer
 * @property-read User|null $filer
 */
#[Fillable(['date', 'post', 'requested_minutes', 'reason'])]
class OvertimeRequest extends Model
{
    use RoutedByStaffKind;

    /** Office staff's overtime: the President or Vice President. Guards': Operations, HR or Admin. */
    public static function reviewAbilities(): array
    {
        return ['office' => Ability::ReviewOfficeOvertime, 'field' => Ability::ReviewOvertime];
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'requested_minutes' => 'integer',
            'approved_minutes' => 'integer',
            'status' => RequestStatus::class,
            'reviewed_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function filer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    /** Added by Operations (or HR/Admin) rather than asked for by the employee. */
    public function addedForThem(): bool
    {
        return $this->filed_by !== null && $this->filed_by !== $this->user_id;
    }

    /**
     * Who it went to: the supervisor picked on an older request, else Operations (a guard)
     * or the President or Vice President (office staff). Extra time Operations added: who added it.
     */
    public function sentToLabel(): string
    {
        if ($this->addedForThem()) {
            $this->loadMissing('filer');

            return 'Added by '.($this->filer->name ?? 'Operations');
        }

        $this->loadMissing(['supervisor', 'user.employeeProfile']);

        return $this->supervisor->name ?? ($this->user->isOfficeStaff() ? 'President or VP' : 'Operations');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @param  Builder<OvertimeRequest>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', RequestStatus::Approved);
    }

    /**
     * @param  Builder<OvertimeRequest>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', RequestStatus::Pending);
    }
}
