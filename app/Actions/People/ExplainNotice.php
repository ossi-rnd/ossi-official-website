<?php

namespace App\Actions\People;

use App\Enums\AuditArea;
use App\Models\NoticeToExplain;
use App\Models\User;
use App\Notifications\NoticeToExplainNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The employee's written explanation, once, within the days given; they can ask
 * for a hearing too. Writing it also counts as receiving the notice. Whoever
 * issued it is told.
 */
final class ExplainNotice
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $employee, NoticeToExplain $notice, string $explanation, bool $wantsHearing): NoticeToExplain
    {
        if ($notice->user_id !== $employee->id) {
            throw new AuthorizationException;
        }

        if (mb_strlen(trim($explanation)) < 20) {
            throw ValidationException::withMessages(['explanation' => 'Write your explanation: what happened, in your words (a few sentences).']);
        }

        return DB::transaction(function () use ($employee, $notice, $explanation, $wantsHearing) {
            $locked = NoticeToExplain::query()->with('issuer')->lockForUpdate()->findOrFail($notice->id);

            if (! $locked->canStillExplain()) {
                throw ValidationException::withMessages(['explanation' => 'The days to explain have passed, or it was already explained.']);
            }

            $now = CarbonImmutable::now();
            $locked->forceFill([
                'explanation' => trim($explanation),
                'wants_hearing' => $wantsHearing,
                'explained_at' => $now,
                'acknowledged_at' => $locked->acknowledged_at ?? $now,
                'status' => NoticeToExplain::EXPLAINED,
            ])->save();

            $this->audit->record(AuditArea::People, 'nte.explained', "{$employee->name} explained: {$locked->subject}", $locked, ['wants_hearing' => $wantsHearing], causer: $employee);

            $locked->issuer?->notify(new NoticeToExplainNotice($locked, NoticeToExplainNotice::EXPLAINED));

            return $locked;
        });
    }
}
