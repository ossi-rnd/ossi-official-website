<?php

namespace App\Providers;

use App\Contracts\ReverseGeocoder;
use App\Enums\Ability;
use App\Models\User;
use App\Services\Geocoding\NominatimReverseGeocoder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReverseGeocoder::class, NominatimReverseGeocoder::class);
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->defineGates();
        $this->defineRateLimiters();

        // Livewire's background requests only re-run middleware on this list.
        // `can:` and `auth` are on it already; `verified` is not.
        Livewire::addPersistentMiddleware([EnsureEmailIsVerified::class]);

        // `composer dev` already runs the server, queue worker, Vite and Reverb. The scheduler too,
        // so patrol and time-away alerts fire locally as they do on the live server.
        DevCommands::artisan('schedule:work', 'scheduler');
    }

    /**
     * One Gate per Ability case. The role lists live in the enum, so this
     * method never changes when access rules do.
     */
    private function defineGates(): void
    {
        foreach (Ability::cases() as $ability) {
            Gate::define($ability->value, fn (User $user): bool => $user->hasAnyRole(...$ability->roles()));
        }
    }

    private function defineRateLimiters(): void
    {
        RateLimiter::for('reverse-geocode', fn (Request $request) => Limit::perMinute((int) config('attendance.reverse_geocode_throttle_per_minute'))
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // A few open tabs ping once a minute each; a flood is not normal.
        RateLimiter::for('session-keep-alive', fn (Request $request) => Limit::perMinute(30)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    private function configureDefaults(): void
    {
        // Outside production: lazy loading (N+1), missing attributes and silently
        // dropped mass assignment all throw, so they're caught in development.
        Model::shouldBeStrict(! $this->app->isProduction());

        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Behind a TLS-terminating proxy (Herd, Laravel Cloud), generate https:// URLs.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Password::defaults(fn (): Password => $this->app->isProduction()
            ? Password::min(12)->mixedCase()->letters()->numbers()->symbols()->uncompromised()
            : Password::min(8));

        // Production always uses the built assets, never Vite's dev server: a `public/hot`
        // left by `npm run dev` (the laptop pilot shares public/ with development) would
        // otherwise point every visitor at localhost:5173 and show the app unstyled.
        if ($this->app->isProduction()) {
            Vite::useHotFile(storage_path('framework/vite-hot-ignored-in-production'));
        }
    }
}
