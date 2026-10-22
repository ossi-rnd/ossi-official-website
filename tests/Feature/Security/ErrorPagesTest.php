<?php

use App\Enums\Role;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

// The error pages: OSSI's own look (seal, card, dark mode), a way forward, and no details leaked.

it('shows a friendly 404 with a way back', function () {
    $this->get('/this-page-does-not-exist')->assertNotFound()
        ->assertSee('Nothing here.')->assertSee('Take me home')->assertSee('ossi-seal.png');
});

it('shows the reason on a 403 when the app gave one, else the general text', function () {
    $general = view('errors.403', ['exception' => new HttpException(403)])->render();
    expect($general)->toContain('Ask your Super Admin if you need access.');

    $reason = view('errors.403', ['exception' => new HttpException(403, 'This employee is outside your region.')])->render();
    expect($reason)->toContain('This employee is outside your region.')->not->toContain('Ask your Super Admin');

    // A real one: a guard opening HR's page.
    $this->actingAs(employee())->get(route('employees'))->assertForbidden()->assertSee('Not for your role.');
});

it('counts down a 429 from Retry-After', function () {
    $html = view('errors.429', ['exception' => new HttpException(429, 'Too Many Attempts.', null, ['Retry-After' => 45])])->render();

    expect($html)->toContain('Slow down a little.')->toContain('45 seconds')->toContain('data-retry disabled');
});

it('has 500 and 503 pages that need no session or database', function () {
    expect(view('errors.500', ['exception' => new HttpException(500)])->render())
        ->toContain('Something broke.')->toContain('tell the IT team the time')
        ->and(view('errors.503', ['exception' => new HttpException(503)])->render())
        ->toContain('updating the app')->toContain('location.reload()');
});

it('sends "Take me home" to the right place for everyone: sign-in, My Attendance or the Dashboard', function () {
    // An error page often can't tell who's signed in (a 404 never starts the session), so the
    // button is neutral and /home decides.
    $this->get('/home')->assertRedirect(route('login'));
    $this->actingAs(employee())->get('/home')->assertRedirect(route('attendance'));
    $this->actingAs(staff(Role::Hr))->get('/home')->assertRedirect(route('dashboard'));
});

it('gives every password field a show / hide button, starting hidden', function () {
    $this->get(route('login'))->assertOk()
        ->assertSee('type="password"', false)->assertSee('aria-label="Show password"', false)->assertSee('aria-controls="password"', false);

    // Register: one button per field, each controlling its own.
    $this->get(route('register'))->assertSee('aria-controls="password_confirmation"', false);
});

it('never shows code details on a server error in production', function () {
    config(['app.debug' => false]);
    Route::get('/__boom', fn () => throw new RuntimeException('secret internals'));

    $this->get('/__boom')->assertStatus(500)->assertSee('Something broke.')->assertDontSee('secret internals');
});
