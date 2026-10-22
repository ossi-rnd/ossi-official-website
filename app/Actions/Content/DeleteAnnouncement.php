<?php

namespace App\Actions\Content;

use App\Enums\AuditArea;
use App\Models\Announcement;
use App\Models\SentAlert;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

final class DeleteAnnouncement
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Announcement $announcement, User $actor): void
    {
        DB::transaction(function () use ($announcement, $actor) {
            $this->audit->record(AuditArea::Content, 'announcement.deleted', "Deleted announcement \"{$announcement->title}\"", $announcement, ['old' => ['title' => $announcement->title, 'starts_at' => $announcement->starts_at->toDateTimeString()]], causer: $actor);

            SentAlert::query()->whereMorphedTo('subject', $announcement)->delete();
            $announcement->delete();
        });
    }
}
