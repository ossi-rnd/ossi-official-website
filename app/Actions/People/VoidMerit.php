<?php

namespace App\Actions\People;

use App\Enums\AuditArea;
use App\Models\MeritRecord;
use App\Models\User;
use App\Notifications\MeritNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes back a merit or demerit given by mistake (or a demerit the employee's
 * reply disproved), with a reason. It stays on record, struck through, and no
 * longer counts. Anyone who may give this person one may void it.
 */
final class VoidMerit
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, MeritRecord $record, string $reason): MeritRecord
    {
        if (! MeritRecord::canGive($actor, $record->user)) {
            throw new AuthorizationException;
        }

        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['voidReason' => 'Say why it\'s taken back (a few words).']);
        }

        return DB::transaction(function () use ($actor, $record, $reason) {
            $locked = MeritRecord::query()->with('user')->lockForUpdate()->findOrFail($record->id);

            if ($locked->voided_at !== null) {
                throw ValidationException::withMessages(['voidReason' => 'It was already taken back.']);
            }

            $locked->forceFill(['voided_at' => CarbonImmutable::now(), 'voided_by' => $actor->id, 'void_reason' => trim($reason)])->save();

            $this->audit->record(AuditArea::People, "{$locked->kind}.voided", "Took back a {$locked->kind} of {$locked->user->name}: {$locked->title}", $locked, [
                'points' => $locked->points,
                'reason' => $locked->void_reason,
            ], causer: $actor);

            $locked->user->notify(new MeritNotice($locked, MeritNotice::VOIDED));

            return $locked;
        });
    }
}
