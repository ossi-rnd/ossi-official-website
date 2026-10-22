<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One guard's duty on one day (the day it starts): a shift template at a
 * client and post. Set by Operations on the Duty Roster; a relief duty is
 * created by AssignReliever and points at the absence notice it covers.
 * Nothing is fillable: the Actions set every column.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $duty_date
 * @property int $shift_id
 * @property int|null $client_id
 * @property string|null $post
 * @property bool $patrols
 * @property int|null $absence_notice_id
 * @property int|null $assigned_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read Shift $shift
 * @property-read Client|null $client
 * @property-read AbsenceNotice|null $absenceNotice
 * @property-read TimeLog|null $timeLog
 */
class DutyAssignment extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'client_id' => null,
        'post' => null,
        'patrols' => false,
        'absence_notice_id' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['duty_date' => 'date', 'patrols' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<AbsenceNotice, $this>
     */
    public function absenceNotice(): BelongsTo
    {
        return $this->belongsTo(AbsenceNotice::class);
    }

    /**
     * The attendance recorded against this duty, if any.
     *
     * @return HasOne<TimeLog, $this>
     */
    public function timeLog(): HasOne
    {
        return $this->hasOne(TimeLog::class);
    }

    public function isRelief(): bool
    {
        return $this->absence_notice_id !== null;
    }

    /** "Metrobank – Imus · Gate 1" */
    public function placeLabel(): string
    {
        return collect([$this->client?->name, $this->post])->filter()->implode(' · ') ?: 'No post set';
    }
}
