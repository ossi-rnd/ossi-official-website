<?php

namespace App\Models;

use App\Enums\RequestStatus;
use App\Enums\TrainingRequestKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guard asking for a training. Admin approves it, usually by adding them to a
 * session (training_id), or declines with a reason. Approved with no session yet
 * means "waiting for a session": the next time Admin adds them to one, it's linked.
 *
 * @property int $id
 * @property int $user_id
 * @property TrainingRequestKind $kind
 * @property string $reason
 * @property CarbonImmutable|null $preferred_from
 * @property RequestStatus $status
 * @property int|null $training_id
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $review_note
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read Training|null $training
 * @property-read User|null $reviewer
 */
#[Fillable(['kind', 'reason', 'preferred_from'])]
class TrainingRequest extends Model
{
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
            'kind' => TrainingRequestKind::class,
            'preferred_from' => 'date',
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
     * @return BelongsTo<Training, $this>
     */
    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Approved, but not in a session yet. */
    public function isWaitingForSession(): bool
    {
        return $this->status === RequestStatus::Approved && $this->training_id === null;
    }

    /** For the employee: "Pending", "Added to Annual firearms refresher (Nov 3, 2026)", "Approved: waiting for a session"… */
    public function outcomeLabel(): string
    {
        $this->loadMissing('training');

        return match (true) {
            $this->status === RequestStatus::Approved && $this->training !== null => "Added to {$this->training->title} ({$this->training->dateLabel()})",
            $this->isWaitingForSession() => 'Approved: waiting for a session',
            $this->status === RequestStatus::Rejected => 'Declined',
            $this->status === RequestStatus::Cancelled => 'Cancelled',
            default => 'Pending',
        };
    }

    /**
     * @param  Builder<TrainingRequest>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', RequestStatus::Pending);
    }
}
