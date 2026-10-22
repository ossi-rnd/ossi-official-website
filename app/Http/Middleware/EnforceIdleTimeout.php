<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a user out after config('security.idle_timeout.minutes') without a request.
 *
 * resources/js/idle-timeout.js is only the UX (countdown, "Stay signed in").
 * THIS is the enforcement: JavaScript can be disabled or the tab closed, but
 * every request, including Livewire's, passes through here.
 */
final class EnforceIdleTimeout
{
    public const SESSION_KEY = 'last_activity_at';

    /**
     * Set by SessionKeepAliveController: a heartbeat may read the clock but
     * only resets it when the browser reports real input.
     */
    public const HEARTBEAT = 'idle_timeout.heartbeat';

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.idle_timeout.enabled') || ! Auth::check()) {
            return $next($request);
        }

        $lastActivity = $request->session()->get(self::SESSION_KEY);

        if (is_int($lastActivity) && time() - $lastActivity >= $this->timeoutSeconds()) {
            return $this->signOut($request);
        }

        $response = $next($request);

        // $request->user(), not Auth::check(): the request itself may have logged the user out.
        // Joining the live-notification channel isn't activity: an unattended tab re-joins on its own
        // whenever its WebSocket reconnects.
        if ($request->user() !== null && ! $request->attributes->get(self::HEARTBEAT, false) && ! $request->is('broadcasting/auth')) {
            $request->session()->put(self::SESSION_KEY, time());
        }

        return $response;
    }

    private function signOut(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // After invalidate(), which empties the session: order matters.
        $request->session()->flash('status', $this->message());

        // Livewire treats 419 as "session expired"; idle-timeout.js turns it into a redirect.
        if ($request->hasHeader('X-Livewire') || $request->expectsJson()) {
            return response()->json(['message' => $this->message()], 419);
        }

        return redirect()->route('login');
    }

    private function message(): string
    {
        $minutes = intdiv($this->timeoutSeconds(), 60);

        return "You were signed out after {$minutes} minutes of inactivity.";
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('security.idle_timeout.minutes')) * 60;
    }
}
