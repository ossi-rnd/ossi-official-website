<?php

namespace App\Services;

use App\Enums\AuditArea;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes one audit entry. Called from inside the same DB transaction as the
 * change it describes, so a change and its audit entry are saved together or
 * not at all. Deliberately NOT queued: an audit row lost in a failed job is
 * worse than a slightly slower request.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        AuditArea $area,
        string $event,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        ?User $causer = null,
    ): ActivityLog {
        $causer ??= auth()->user();

        $entry = new ActivityLog;
        $entry->forceFill([
            'log_name' => $area,
            'event' => $event,
            'description' => $description,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'causer_id' => $causer instanceof User ? $causer->id : null,
            'properties' => $properties === [] ? null : $properties,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ])->save();

        return $entry;
    }

    /**
     * Before/after values of the keys that changed. Sensitive keys are listed
     * as changed without their values, so the audit log doesn't become a
     * second copy of the government IDs it is meant to protect.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>  $masked
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    public static function diff(array $before, array $after, array $masked = []): array
    {
        $old = [];
        $new = [];

        foreach ($after as $key => $value) {
            $previous = $before[$key] ?? null;

            if (self::normalize($previous) === self::normalize($value)) {
                continue;
            }

            $isMasked = in_array($key, $masked, true);
            $old[$key] = $isMasked ? '(hidden)' : self::normalize($previous);
            $new[$key] = $isMasked ? '(changed)' : self::normalize($value);
        }

        return ['old' => $old, 'new' => $new];
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            // An empty list is no value, like an empty string (a new "other IDs" isn't a change).
            default => $value === '' || $value === [] ? null : $value,
        };
    }
}
