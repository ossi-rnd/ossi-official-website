<?php

namespace App\Actions\Content;

use App\Enums\AuditArea;
use App\Models\Announcement;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

final class SaveAnnouncement
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SendAnnouncementNotifications $notify,
    ) {}

    /**
     * @param  array{title: string, body: string, audience: list<string>, is_urgent: bool, starts_at: string, ends_at: string|null}  $data
     */
    public function handle(?Announcement $announcement, array $data, User $actor): Announcement
    {
        $announcement = DB::transaction(function () use ($announcement, $data, $actor) {
            $isNew = $announcement === null;
            $announcement ??= new Announcement;
            $fields = ['title', 'body', 'audience', 'is_urgent', 'starts_at', 'ends_at'];
            $before = $isNew ? [] : $announcement->only($fields);

            $announcement->fill([...$data, 'audience' => $data['audience'] === [] ? null : $data['audience']]);

            if ($isNew) {
                $announcement->created_by = $actor->id;
            }

            $announcement->save();

            $after = $announcement->only($fields);
            $this->audit->record(AuditArea::Content, $isNew ? 'announcement.created' : 'announcement.updated', "Saved announcement \"{$announcement->title}\"", $announcement, AuditLogger::diff(
                array_map(fn ($value) => is_array($value) ? implode(', ', $value) : $value, $before),
                array_map(fn ($value) => is_array($value) ? implode(', ', $value) : $value, $after),
            ), causer: $actor);

            return $announcement;
        });

        // Outside the save: a notification problem must never undo the announcement itself.
        $this->notify->handle($announcement);

        return $announcement;
    }
}
