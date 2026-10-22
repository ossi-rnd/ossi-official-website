<?php

namespace App\Actions\People;

use App\Enums\AuditArea;
use App\Models\MeritRecord;
use App\Models\MeritType;
use App\Models\User;
use App\Notifications\MeritNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A merit or demerit for one employee, by whoever supervises them
 * (MeritRecord::canGive). Usually a type from HR's list, whose points are
 * copied; "Other" takes a title and points (±1 to ±20) of its own. A reason is
 * always required. The employee is told, and may add their side to a demerit
 * within REPLY_DAYS.
 *
 * Nothing here touches pay: penalty deductions need written consent (Labor
 * Code Art. 113). Serious offences go through a Notice to Explain.
 */
final class GiveMerit
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  int|null  $otherPoints  signed, for "Other" only: + merit, − demerit
     */
    public function handle(User $giver, User $employee, ?MeritType $type, string $reason, CarbonImmutable $occurredOn, ?string $otherTitle = null, ?int $otherPoints = null): MeritRecord
    {
        if (! MeritRecord::canGive($giver, $employee)) {
            throw new AuthorizationException;
        }

        $today = CarbonImmutable::today();
        $errors = array_filter([
            'reason' => mb_strlen(trim($reason)) < 5 ? 'Say what happened (a few words).' : null,
            'occurredOn' => match (true) {
                $occurredOn->isAfter($today) => 'The date can\'t be in the future.',
                $occurredOn->isBefore($today->subDays(90)) => 'Only the last 90 days.',
                default => null,
            },
            'typeId' => $type !== null && ! $type->is_active ? 'That type is no longer used.' : null,
            'otherTitle' => $type === null && mb_strlen(trim((string) $otherTitle)) < 3 ? 'Name it (e.g. "Helped a lost visitor").' : null,
            'otherPoints' => $type === null && ($otherPoints === null || $otherPoints === 0 || abs($otherPoints) > 20) ? 'Points from 1 to 20.' : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $points = $type !== null ? $type->signedPoints() : (int) $otherPoints;
        $kind = $points < 0 ? MeritType::DEMERIT : MeritType::MERIT;

        return DB::transaction(function () use ($giver, $employee, $type, $reason, $occurredOn, $otherTitle, $points, $kind, $today) {
            $record = new MeritRecord;
            $record->forceFill([
                'user_id' => $employee->id,
                'merit_type_id' => $type?->id,
                'kind' => $kind,
                'title' => $type !== null ? $type->name : trim((string) $otherTitle),
                'points' => $points,
                'reason' => trim($reason),
                'occurred_on' => $occurredOn->toDateString(),
                'given_by' => $giver->id,
                'reply_until' => $kind === MeritType::DEMERIT ? $today->addDays(MeritRecord::REPLY_DAYS)->toDateString() : null,
            ])->save();

            $this->audit->record(AuditArea::People, "{$kind}.given", ucfirst($kind)." for {$employee->name}: {$record->title} (".sprintf('%+d', $points).')', $record, [
                'points' => $points,
                'reason' => $record->reason,
                'occurred_on' => $record->occurred_on->toDateString(),
            ], causer: $giver);

            $employee->notify(new MeritNotice($record, MeritNotice::GIVEN));

            return $record;
        });
    }
}
