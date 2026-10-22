<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a signed-in user on the privacy notice until they acknowledge its
 * CURRENT version (RA 10173 sec. 16(a)). In the `web` group, so Livewire
 * requests can't skip it by never loading a new page.
 */
final class RequirePrivacyAcknowledgement
{
    /**
     * Routes a user must still reach, or the gate becomes a trap.
     */
    private const ALLOWED = [
        'privacy-notice',
        'privacy-notice.acknowledge',
        'logout',
        'session.keep-alive',
        'verification.notice',
        'verification.verify',
        'verification.send',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->hasAcknowledgedPrivacyNotice()) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return $next($request);
        }

        if ($request->hasHeader('X-Livewire') || $request->expectsJson()) {
            return response()->json(['message' => 'Please acknowledge the privacy notice first.'], 403);
        }

        // guest() remembers the URL, so they land where they were going afterwards.
        return redirect()->guest(route('privacy-notice'));
    }
}
