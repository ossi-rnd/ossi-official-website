<?php

namespace App\Models;

use App\Enums\Ability;
use App\Support\LeaveRouting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The phone a person clocks in with. The browser keeps a random code in a
 * cookie; only its SHA-256 is stored, so the table can't be used to pose as
 * the phone. Nothing is fillable: CheckPunchDevice and SetGuardPhone write it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string|null $label
 * @property CarbonImmutable $registered_at
 * @property int|null $registered_by
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read User|null $registeredBy
 */
class UserDevice extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Operation HQ, HR (and Super Admin) for anyone; a Satellite Operation for its region's guards. */
    public static function canManage(User $actor, User $guard): bool
    {
        if (! $actor->can(Ability::ManageGuardPhones->value) || $actor->is($guard)) {
            return false;
        }

        return ! $actor->isRegionallyLimited() || LeaveRouting::regionOf($guard) === $actor->operations_region;
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
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
