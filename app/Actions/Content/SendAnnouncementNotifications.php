<?php

namespace App\Actions\Content;

use App\Enums\Role;
use App\Models\Announcement;
use App\Models\SentAlert;
use App\Models\User;
use App\Notifications\AnnouncementPublished;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Notifies an announcement's audience once it is live. Called right after a
 * save (when it starts immediately) and by the scheduler (for ones that start
 * later). The sent_alerts claim makes both paths safe to overlap.
 */
final class SendAnnouncementNotifications
{
    /**
     * @return bool whether notifications were sent now
     */
    public function handle(Announcement $announcement): bool
    {
        if (! $announcement->isLive()) {
            return false;
        }

        return DB::transaction(function () use ($announcement) {
            if (! SentAlert::claim('announcement.published', $announcement)) {
                return false;
            }

            $roles = $announcement->audienceRoles() ?: Role::cases();

            // Chunked: one query and one batch of queued jobs per 200 people.
            User::query()->notifiable($roles)->chunkById(200, function (Collection $users) use ($announcement) {
                Notification::send($users, new AnnouncementPublished($announcement));
            });

            return true;
        });
    }
}
