<?php

namespace App\Actions\People;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\MeritType;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * HR's list of merit and demerit types. A type in use is never deleted, only
 * switched off: records copy the title and points, so history doesn't change.
 */
final class SaveMeritType
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, ?MeritType $type, string $name, string $kind, int $points, ?string $description, bool $active = true): MeritType
    {
        if (! $actor->can(Ability::ManageMeritTypes->value)) {
            throw new AuthorizationException;
        }

        $errors = array_filter([
            'typeName' => mb_strlen(trim($name)) < 3 ? 'Name it.' : null,
            'typeKind' => ! in_array($kind, [MeritType::MERIT, MeritType::DEMERIT], true) ? 'Merit or demerit.' : null,
            'typePoints' => $points < 1 || $points > 20 ? 'Points from 1 to 20.' : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $type ??= new MeritType;
        $creating = ! $type->exists;
        $type->forceFill(['name' => trim($name), 'kind' => $kind, 'points' => $points, 'description' => $description !== null && trim($description) !== '' ? trim($description) : null, 'is_active' => $active])->save();

        $this->audit->record(AuditArea::People, $creating ? 'merit_type.created' : 'merit_type.updated', ($creating ? 'Added' : 'Changed')." the {$kind} type \"{$type->name}\" ({$points} points".($active ? '' : ', off').')', $type, causer: $actor);

        return $type;
    }
}
