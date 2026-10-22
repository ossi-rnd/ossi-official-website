<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A value an Admin edits in the app, keyed by name. Read with
 * Setting::value('key'); write only through an Action, which also audits the
 * change. (Unused since the GCash QR went away; kept for the next setting.)
 *
 * @property string $key
 * @property mixed $value
 * @property int|null $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function value(string $key): array
    {
        $value = static::query()->find($key)?->value;

        return is_array($value) ? $value : [];
    }
}
