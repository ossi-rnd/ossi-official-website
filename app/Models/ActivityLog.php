<?php

namespace App\Models;

use App\Enums\AuditArea;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One audit entry. Append-only: an audit trail that can be edited proves nothing.
 *
 * @property int $id
 * @property AuditArea $log_name
 * @property string $event
 * @property string $description
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int|null $causer_id
 * @property array<string, mixed>|null $properties
 * @property string|null $ip_address
 * @property CarbonImmutable $created_at
 * @property-read User|null $causer
 * @property-read Model|null $subject
 */
class ActivityLog extends Model
{
    protected $table = 'activity_log';

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit entries cannot be deleted.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'log_name' => AuditArea::class,
            'properties' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
