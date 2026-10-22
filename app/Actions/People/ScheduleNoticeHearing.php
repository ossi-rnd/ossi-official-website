<?php

namespace App\Actions\People;

use App\Enums\AuditArea;
use App\Models\NoticeToExplain;
use App\Models\User;
use App\Notifications\NoticeToExplainNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * A hearing or conference: when the employee asks for one, or the facts need it.
 * Set by whoever may decide the notice; the employee is told when and where.
 */
final class ScheduleNoticeHearing
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, NoticeToExplain $notice, CarbonImmutable $at, string $place): NoticeToExplain
    {
        if (! NoticeToExplain::canIssue($actor, $notice->user)) {
            throw new AuthorizationException;
        }

        $errors = array_filter([
            'hearingAt' => $at->isPast() ? 'Choose a time from now on.' : null,
            'hearingPlace' => mb_strlen(trim($place)) < 3 ? 'Say where (e.g. HQ conference room, or a video call link).' : null,
            'decision' => $notice->isDecided() ? 'It was already decided.' : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $notice->forceFill(['hearing_at' => $at, 'hearing_place' => trim($place)])->save();

        $this->audit->record(AuditArea::People, 'nte.hearing_set', "Hearing for {$notice->user->name}: {$notice->subject}", $notice, ['at' => $at->toIso8601String(), 'place' => $notice->hearing_place], causer: $actor);

        $notice->user->notify(new NoticeToExplainNotice($notice, NoticeToExplainNotice::HEARING));

        return $notice;
    }
}
