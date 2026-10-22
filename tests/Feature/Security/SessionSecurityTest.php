<?php

use App\Enums\EmploymentStatus;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Models\ActivityLog;
use App\Models\User;

describe('privacy notice', function () {
    it('holds a user on the notice until they acknowledge it', function () {
        $user = User::factory()->privacyPending()->create();

        $this->actingAs($user)->get(route('attendance'))->assertRedirect(route('privacy-notice'));
        $this->actingAs($user)->get(route('privacy-notice'))->assertOk()->assertSee('I have read and understood this notice');
    });

    it('records the acknowledged version and returns the user to where they were going', function () {
        $user = User::factory()->privacyPending()->create();

        $this->actingAs($user)->get(route('leave'));
        $this->actingAs($user)->post(route('privacy-notice.acknowledge'))->assertRedirect(route('leave'));

        expect($user->fresh()->privacy_notice_version)->toBe(config('privacy.version'))
            ->and(ActivityLog::query()->where('event', 'privacy.acknowledged')->exists())->toBeTrue();
    });

    it('re-prompts everyone when the notice version changes', function () {
        $user = User::factory()->create();
        config()->set('privacy.version', '2099-01-01.1');

        $this->actingAs($user)->get(route('attendance'))->assertRedirect(route('privacy-notice'));
    });

    it('is readable by guests, before registering', function () {
        $this->get(route('privacy-notice'))->assertOk()->assertSee('Privacy Notice')->assertDontSee('I have read and understood');
    });
});

describe('idle timeout', function () {
    it('signs out a session idle for longer than the limit', function () {
        $this->actingAs(employee())
            ->withSession([EnforceIdleTimeout::SESSION_KEY => time() - 16 * 60])
            ->get(route('attendance'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });

    it('keeps an active session alive', function () {
        $this->actingAs(employee())
            ->withSession([EnforceIdleTimeout::SESSION_KEY => time() - 5 * 60])
            ->get(route('attendance'))
            ->assertOk();
    });

    it('answers Livewire requests from an expired session with 419', function () {
        $this->actingAs(employee())
            ->withSession([EnforceIdleTimeout::SESSION_KEY => time() - 16 * 60])
            ->withHeader('X-Livewire', 'true')
            ->get(route('attendance'))
            ->assertStatus(419);
    });

    it('only resets the clock on a keep-alive that reports activity', function () {
        $stale = time() - 10 * 60;

        $this->actingAs(employee())
            ->withSession([EnforceIdleTimeout::SESSION_KEY => $stale])
            ->postJson(route('session.keep-alive'), ['active' => false])
            ->assertOk()
            ->assertSessionHas(EnforceIdleTimeout::SESSION_KEY, $stale);
    });
});

describe('deactivated accounts and headers', function () {
    it('ends the session of someone HR terminates', function () {
        $user = employee();
        $user->employeeProfile?->forceFill(['employment_status' => EmploymentStatus::Terminated])->save();

        $this->actingAs($user)->get(route('attendance'))->assertRedirect(route('login'));
        $this->assertGuest();
    });

    it('sends security headers and never caches signed-in pages', function () {
        $response = $this->actingAs(employee())->get(route('attendance'))
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Permissions-Policy', 'camera=(self), geolocation=(self), microphone=(), payment=(), usb=()')
            ->assertHeaderMissing('Strict-Transport-Security');

        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('escapes what users type', function () {
        $user = employee();
        $user->forceFill(['name' => '<script>alert(1)</script>'])->save();

        $this->actingAs($user)->get(route('profile'))
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    });
});
