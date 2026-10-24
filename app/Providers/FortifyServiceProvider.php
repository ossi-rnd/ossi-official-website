<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\LogoutResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Fortify;

/**
 * Fortify is headless: it owns the auth ROUTES and logic (login throttling,
 * session regeneration, password reset tokens, email verification, 2FA),
 * and we only supply views and a few hooks.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
    }

    public function boot(): void
    {
        $this->registerViews();

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::authenticateUsing($this->authenticate(...));

        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate(Str::lower($request->string(Fortify::username())->toString()));

            return [
                // One account: 5 tries a minute (guessing one person's password).
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                // One IP: 60 tries a minute across all emails (one password, many accounts).
                // Not lower: Smart / Globe mobile data puts many phones behind one shared address,
                // and a site's guards may share one Wi-Fi, so a shift change signs in many at once
                // from one IP (20 blocked the 21st guard in the 5,000-guard load test, 2026-10-24).
                Limit::perMinute(60)->by('ip|'.$request->ip()),
            ];
        });

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by((string) $request->session()->get('login.id')));
    }

    /**
     * Same check as Fortify's default, plus one rule: people HR has marked
     * resigned or terminated can't sign in, even with the right password.
     */
    private function authenticate(Request $request): ?User
    {
        $user = User::query()
            ->with('employeeProfile')
            // In any case: a test account keeps its number's capitals (260627PAM-0950@test.invalid).
            ->whereRaw('lower(email) = ?', [Str::lower($request->string('email')->toString())])
            ->first();

        if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
            return null;
        }

        if ($user->employeeProfile !== null && ! $user->employeeProfile->employment_status->isActive()) {
            throw ValidationException::withMessages([Fortify::username() => 'This account has been deactivated. Please contact HR.']);
        }

        return $user;
    }

    private function registerViews(): void
    {
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::registerView(fn () => view('auth.register'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn () => view('auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
    }
}
