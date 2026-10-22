<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $start_time "HH:MM" or "HH:MM:SS"
 * @property string $end_time
 * @property int $grace_minutes
 * @property bool $is_default
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['name', 'start_time', 'end_time', 'grace_minutes', 'is_default'])]
class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'grace_minutes' => 0,
        'is_default' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grace_minutes' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return HasMany<EmployeeProfile, $this>
     */
    public function employeeProfiles(): HasMany
    {
        return $this->hasMany(EmployeeProfile::class);
    }

    /**
     * The shift marked default, or an unsaved one built from config so the
     * attendance rules always have a schedule (spec: 09:00–18:00, 10 min grace).
     */
    public static function fallback(): self
    {
        $default = static::query()->where('is_default', true)->first();

        if ($default !== null) {
            return $default;
        }

        /** @var array{name: string, start_time: string, end_time: string, grace_minutes: int} $config */
        $config = config('attendance.default_shift');

        return new self($config);
    }

    /**
     * True for shifts such as 22:00–06:00 that end the next day.
     */
    public function crossesMidnight(): bool
    {
        return $this->minutesOfDay($this->end_time) <= $this->minutesOfDay($this->start_time);
    }

    public function scheduledMinutes(): int
    {
        $minutes = $this->minutesOfDay($this->end_time) - $this->minutesOfDay($this->start_time);

        return $minutes <= 0 ? $minutes + 24 * 60 : $minutes;
    }

    public function label(): string
    {
        return sprintf('%s · %s – %s', $this->name, substr($this->start_time, 0, 5), substr($this->end_time, 0, 5));
    }

    /** "06:00–15:00", for tight spaces like a roster cell. */
    public function timeRange(): string
    {
        return substr($this->start_time, 0, 5).'–'.substr($this->end_time, 0, 5);
    }

    private function minutesOfDay(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }
}
