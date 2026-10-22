<?php

use App\Enums\Role;
use App\Events\NotificationArrived;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Livewire\NotificationBell;
use App\Notifications\PatrolAlert;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

// The bell updates live: each new bell notification is pushed over the
// WebSocket to its owner only, without polling.

/** A Reverb connection with throwaway credentials, and the channel rules registered on it. */
function useReverb(string $host = '127.0.0.1', int $port = 1): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb' => [
            'driver' => 'reverb', 'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => 'test',
            'options' => ['host' => $host, 'port' => $port, 'scheme' => 'http', 'useTLS' => false],
            'client_options' => ['timeout' => 1, 'connect_timeout' => 1],
        ],
    ]);
    Broadcast::purge();
    require base_path('routes/channels.php');
}

// With the database queue and no worker running (the usual local set-up when
// `composer dev` isn't up), the bell item still appears at once; only the email waits.
it('writes the bell item at once, leaving only the email for the queue worker', function () {
    config(['queue.default' => 'database']);
    $ops = staff(Role::OperationsHq);

    $ops->notify(new PatrolAlert('Maria Santos', 'Not back yet', 'Expected back 10:20 AM', route('patrols')));

    expect($ops->notifications()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and((string) DB::table('jobs')->value('payload'))->toContain('mail');
});

it('pushes every bell notification to its owner, with what the bell shows and the unread count', function () {
    Event::fake([NotificationArrived::class]);
    $ops = staff(Role::OperationsHq);

    $ops->notify(new PatrolAlert('Maria Santos', 'Round 2 missed', 'OSSI Test Site (QR)', route('patrols')));

    Event::assertDispatched(NotificationArrived::class, function (NotificationArrived $event) use ($ops) {
        expect($event->broadcastOn()->name)->toBe("private-App.Models.User.{$ops->id}")
            ->and($event->broadcastAs())->toBe('notification.arrived')
            ->and($event->broadcastWith())->toMatchArray([
                'title' => 'Maria Santos: Round 2 missed',
                'body' => 'OSSI Test Site (QR)',
                'url' => route('patrols'),
                'icon' => 'shield',
                'unread' => 1,
            ]);

        return true;
    });
});

it('lets people listen only on their own channel', function () {
    useReverb();
    $me = staff(Role::Hr);
    $someoneElse = staff(Role::Hr);

    $this->actingAs($me)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$me->id}"])
        ->assertOk()->assertJsonStructure(['auth']);
    $this->actingAs($me)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$someoneElse->id}"])
        ->assertForbidden();
});

it('does not count joining the channel as activity, so an unattended screen still signs out', function () {
    useReverb();
    $me = staff(Role::Hr);
    $tenMinutesAgo = time() - 600;

    $this->actingAs($me)->withSession([EnforceIdleTimeout::SESSION_KEY => $tenMinutesAgo])
        ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$me->id}"])
        ->assertOk()
        ->assertSessionHas(EnforceIdleTimeout::SESSION_KEY, $tenMinutesAgo);
});

it('still saves the notification once when the WebSocket server is down', function () {
    useReverb(port: 1); // nothing listens there
    $ops = staff(Role::OperationsHq);

    $ops->notify(new PatrolAlert('Maria Santos', 'Round 2 missed', 'OSSI Test Site (QR)'));

    expect($ops->notifications()->count())->toBe(1);
});

it('gives the bell the unread count to start the live badge from', function () {
    $ops = staff(Role::OperationsHq);
    $ops->notify(new PatrolAlert('Maria Santos', 'Round 2 missed', 'OSSI Test Site (QR)'));

    Livewire::actingAs($ops)->test(NotificationBell::class)
        ->assertSeeHtml('data-unread="1"')
        ->assertSeeHtml("liveBell({ userId: {$ops->id}, unread: 1 })")
        ->call('markAllRead')
        ->assertSeeHtml('data-unread="0"');
});
