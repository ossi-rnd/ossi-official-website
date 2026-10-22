<?php

namespace App\Console\Commands;

use App\Actions\Content\SendAnnouncementNotifications;
use App\Models\Announcement;
use Illuminate\Console\Command;

/**
 * Announcements saved with a future start notify their audience when that
 * start arrives. Scheduled every five minutes in routes/console.php.
 */
final class PublishScheduledAnnouncements extends Command
{
    protected $signature = 'app:publish-announcements';

    protected $description = 'Notify the audience of announcements that have just gone live';

    public function handle(SendAnnouncementNotifications $notify): int
    {
        $sent = 0;

        Announcement::query()
            ->live()
            ->whereDoesntHave('sentAlerts')
            ->each(function (Announcement $announcement) use ($notify, &$sent) {
                $sent += $notify->handle($announcement) ? 1 : 0;
            });

        $this->info("Published {$sent} ".str('announcement')->plural($sent).'.');

        return self::SUCCESS;
    }
}
