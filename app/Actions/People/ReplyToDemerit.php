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
 * The employee's side of a demerit, once, within MeritRecord::REPLY_DAYS. It's
 * kept next to the demerit for anyone who reads it later, and whoever gave it
 * is told (they can void it if the reply changes things).
 */
final class ReplyToDemerit
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $employee, MeritRecord $record, string $reply): MeritRecord
    {
        if ($record->user_id !== $employee->id) {
            throw new AuthorizationException;
        }

        if (mb_strlen(trim($reply)) < 5) {
            throw ValidationException::withMessages(['reply' => 'Write your side (a few words).']);
        }

        return DB::transaction(function () use ($employee, $record, $reply) {
            $locked = MeritRecord::query()->with('giver')->lockForUpdate()->findOrFail($record->id);

            if (! $locked->awaitsReply()) {
                throw ValidationException::withMessages(['reply' => 'This can\'t be answered any more.']);
            }

            $locked->forceFill(['employee_reply' => trim($reply), 'replied_at' => CarbonImmutable::now()])->save();

            $this->audit->record(AuditArea::People, 'demerit.replied', "{$employee->name} replied to a demerit: {$locked->title}", $locked, causer: $employee);

            $locked->giver?->notify(new MeritNotice($locked, MeritNotice::REPLIED));

            return $locked;
        });
    }
}
