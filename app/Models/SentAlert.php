<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * "This alert was already sent about this record." The unique index on
 * (alert, subject) is what makes a scheduled check safe to run twice.
 *
 * @property int $id
 * @property string $alert
 * @property string $subject_type
 * @property int $subject_id
 * @property CarbonImmutable $sent_at
 * @property-read Model $subject
 */
#[Fillable(['alert', 'subject_type', 'subject_id', 'sent_at'])]
class SentAlert extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Claims the alert for this subject. True only the first time: a second
     * run, even a concurrent one, hits the unique index and gets false.
     */
    public static function claim(string $alert, Model $subject): bool
    {
        $row = static::query()->createOrFirst(
            ['alert' => $alert, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey()],
            ['sent_at' => CarbonImmutable::now()],
        );

        return $row->wasRecentlyCreated;
    }
}
