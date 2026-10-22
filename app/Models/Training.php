<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A training session for guards (pre-licensing, refresher, firearms, first
 * aid). Who attended, and whether they completed it, is on TrainingAttendee.
 *
 * @property int $id
 * @property string $title
 * @property string|null $provider
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property string|null $venue
 * @property CarbonImmutable|null $valid_until
 * @property string|null $notes
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, TrainingAttendee> $attendees
 * @property-read int|null $attendees_count
 * @property-read int|null $completed_count
 */
#[Fillable(['title', 'provider', 'starts_on', 'ends_on', 'venue', 'valid_until', 'notes'])]
class Training extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'valid_until' => 'date',
        ];
    }

    /**
     * @return HasMany<TrainingAttendee, $this>
     */
    public function attendees(): HasMany
    {
        return $this->hasMany(TrainingAttendee::class);
    }

    /** "Oct 20, 2026" or "Oct 20 – 22, 2026" */
    public function dateLabel(): string
    {
        if ($this->ends_on === null || $this->ends_on->isSameDay($this->starts_on)) {
            return $this->starts_on->format('M j, Y');
        }

        return $this->starts_on->isSameMonth($this->ends_on)
            ? $this->starts_on->format('M j').' – '.$this->ends_on->format('j, Y')
            : $this->starts_on->format('M j').' – '.$this->ends_on->format('M j, Y');
    }
}
