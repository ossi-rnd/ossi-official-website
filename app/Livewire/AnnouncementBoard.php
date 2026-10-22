<?php

namespace App\Livewire;

use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Announcement;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Announcements for this person, to read again after the banner was
 * dismissed or ended: the page an announcement in the bell opens (?id=
 * highlights that one). Publishers write them on Admin → Announcements.
 *
 * @property-read Collection<int, Announcement> $announcements
 */
#[Title('Announcements')]
class AnnouncementBoard extends Component
{
    use InteractsWithUser;

    /** How far back the page reaches; older advisories are no longer news. */
    public const int DAYS = 90;

    #[Url]
    public ?int $id = null;

    /**
     * Started in the last 90 days or still live, for this person's roles, newest first.
     *
     * @return Collection<int, Announcement>
     */
    #[Computed]
    public function announcements(): Collection
    {
        $user = $this->currentUser();
        $now = CarbonImmutable::now();

        return Announcement::query()
            ->where('starts_at', '<=', $now)
            ->where(fn ($query) => $query->where('starts_at', '>=', $now->subDays(self::DAYS))->orWhereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->latest('starts_at')
            ->limit(100)
            ->get()
            ->filter(fn (Announcement $announcement) => $announcement->isFor($user))
            ->values()
            ->toBase();
    }

    public function render(): View
    {
        return view('livewire.announcement-board');
    }
}
