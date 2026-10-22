<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * When HR marks someone resigned or terminated, their open sessions end on
 * the next request. (FortifyServiceProvider already refuses their sign-in.)
 */
final class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $user->loadMissing('employeeProfile');

        if ($user->employeeProfile === null || $user->employeeProfile->employment_status->isActive()) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('status', 'Your account has been deactivated. Please contact HR.');

        return $request->hasHeader('X-Livewire') || $request->expectsJson()
            ? response()->json(['message' => 'Account deactivated.'], 419)
            : redirect()->route('login');
    }
}
