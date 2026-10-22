<?php

use App\Enums\Role;
use App\Livewire\Admin\Announcements;
use App\Livewire\Help;
use App\Livewire\NotificationBell;
use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\Faq;
use App\Models\SentAlert;
use App\Notifications\AnnouncementPublished;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

function publishAnnouncement(array $overrides = []): void
{
    $component = Livewire::actingAs(staff(Role::Hr))->test(Announcements::class)->call('create');

    foreach (['title' => 'Typhoon advisory', 'body' => 'Report to your post only if safe.', ...$overrides] as $field => $value) {
        $component->set($field, $value);
    }

    $component->call('save')->assertHasNoErrors();
}

it('publishes an announcement, notifies its audience once and audits it', function () {
    Notification::fake();
    $guard = employee();
    $accountant = staff(Role::Accounting);

    publishAnnouncement(['audience' => [Role::Employee->value]]);

    $announcement = Announcement::query()->sole();
    Notification::assertSentTo($guard, AnnouncementPublished::class);
    Notification::assertNotSentTo($accountant, AnnouncementPublished::class);
    expect(ActivityLog::query()->where('event', 'announcement.created')->count())->toBe(1);

    // The scheduler runs again: nothing is sent twice.
    $this->artisan('app:publish-announcements')->assertSuccessful();
    Notification::assertSentToTimes($guard, AnnouncementPublished::class, 1);
    expect(SentAlert::query()->whereMorphedTo('subject', $announcement)->count())->toBe(1);
});

it('emails urgent announcements and keeps routine ones in the bell', function () {
    $guard = employee();

    expect((new AnnouncementPublished(new Announcement(['is_urgent' => true])))->via($guard))->toBe(['database', 'mail'])
        ->and((new AnnouncementPublished(new Announcement(['is_urgent' => false])))->via($guard))->toBe(['database']);
});

it('waits for a scheduled announcement\'s start before notifying anyone', function () {
    Notification::fake();
    $guard = employee();

    publishAnnouncement(['startsAt' => CarbonImmutable::now()->addHour()->format('Y-m-d\TH:i')]);
    Notification::assertNothingSent();

    $this->travel(61)->minutes();
    $this->artisan('app:publish-announcements')->assertSuccessful();

    Notification::assertSentTo($guard, AnnouncementPublished::class);
});

it('shows live announcements as a banner only to their audience', function () {
    Announcement::create(['title' => 'Payday moved', 'body' => 'Pay is on the 16th.', 'audience' => [Role::Employee->value], 'starts_at' => now()->subMinute()]);
    Announcement::create(['title' => 'Old news', 'body' => 'Ended.', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);

    $this->actingAs(employee())->get(route('attendance'))->assertSee('Payday moved')->assertDontSee('Old news');
    $this->actingAs(staff(Role::Accounting))->get(route('attendance'))->assertDontSee('Payday moved');
});

it('refuses announcement writes without the publish-content gate', function () {
    Livewire::actingAs(staff(Role::Accounting))->test(Announcements::class)->call('create')->assertForbidden();
});

it('validates the end after the start', function () {
    Livewire::actingAs(staff(Role::Admin))->test(Announcements::class)
        ->call('create')
        ->set('title', 'Drill')
        ->set('body', 'Fire drill at 10.')
        ->set('startsAt', '2026-10-10T10:00')
        ->set('endsAt', '2026-10-10T09:00')
        ->call('save')
        ->assertHasErrors(['endsAt' => 'after']);
});

it('marks an announcement read and opens it on the Announcements page', function () {
    $guard = employee();
    $announcement = Announcement::create(['title' => 'Hello', 'body' => 'World', 'starts_at' => now()]);
    $guard->notify(new AnnouncementPublished($announcement));
    $notification = $guard->notifications()->sole();

    Livewire::actingAs($guard)->test(NotificationBell::class)
        ->assertSee('Hello')
        ->call('open', $notification->id)
        ->assertRedirect(route('news', ['id' => $announcement->id]));

    expect($notification->fresh()?->read_at)->not->toBeNull();

    // Someone else's notification id is simply not found.
    Livewire::actingAs(employee())->test(NotificationBell::class)->call('open', $notification->id)->assertNotFound();
});

it('shows everyone the announcements meant for them, from the last 90 days', function () {
    Announcement::create(['title' => 'For everyone', 'body' => 'Drill at 10.', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);
    Announcement::create(['title' => 'For HR only', 'body' => 'Payroll cut-off.', 'starts_at' => now(), 'audience' => [Role::Hr->value]]);
    Announcement::create(['title' => 'Long ago', 'body' => 'Old news.', 'starts_at' => now()->subDays(120), 'ends_at' => now()->subDays(100)]);
    Announcement::create(['title' => 'Not yet', 'body' => 'Tomorrow.', 'starts_at' => now()->addDay()]);

    $this->actingAs(employee())->get(route('news'))
        ->assertOk()
        ->assertSee('For everyone')->assertSee('Ended')
        ->assertDontSee('For HR only')->assertDontSee('Long ago')->assertDontSee('Not yet');

    $this->actingAs(staff(Role::Hr))->get(route('news'))->assertSee('For HR only');

    // Live ones are listed once here, not again as a banner above the list.
    Announcement::create(['title' => 'Live now', 'body' => 'Gate B closed.', 'starts_at' => now()->subHour()]);
    expect(substr_count($this->actingAs(employee())->get(route('news'))->getContent(), 'Live now'))->toBe(1);
});

// The queue worker builds links from APP_URL; someone on a Cloudflare tunnel (or after a
// domain change) must still land on the page, on the address they are using.
it('opens a notification link on the current address, whatever host it was stored with', function () {
    $guard = employee();
    $guard->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'test',
        'data' => ['title' => 'Leave', 'body' => 'Decided', 'url' => 'https://old-host.example/leave?tab=mine'],
    ]);

    Livewire::actingAs($guard)->test(NotificationBell::class)
        ->call('open', $guard->notifications()->sole()->id)
        ->assertRedirect(url('/leave?tab=mine'));
});

it('lets HR maintain FAQs while employees only read published ones', function () {
    Livewire::actingAs(staff(Role::Hr))->test(Help::class)
        ->call('create')
        ->set('question', 'When is payday?')
        ->set('answer', "The 15th and the last day.\nIf it falls on a Sunday, the day before.")
        ->call('save')
        ->assertHasNoErrors()
        ->call('create')
        ->set('question', 'Draft question')
        ->set('answer', 'Not ready.')
        ->set('isPublished', false)
        ->call('save');

    expect(Faq::query()->count())->toBe(2)
        ->and(ActivityLog::query()->where('event', 'faq.created')->count())->toBe(2);

    Livewire::actingAs(employee())->test(Help::class)
        ->assertSee('When is payday?')
        ->assertSeeHtml('The 15th and the last day.<br />')
        ->assertDontSee('Draft question')
        ->call('create')->assertForbidden();
});

it('escapes FAQ answers instead of rendering them as HTML', function () {
    Faq::create(['question' => 'XSS?', 'answer' => '<script>alert(1)</script>']);

    Livewire::actingAs(employee())->test(Help::class)
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('&lt;script&gt;');
});
