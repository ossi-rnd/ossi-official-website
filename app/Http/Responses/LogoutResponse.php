<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

/**
 * Fortify lets you swap any of its responses by binding the contract. The
 * idle countdown submits the logout form with reason=idle, so the login page
 * can say WHY you were signed out.
 */
final class LogoutResponse implements LogoutResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): RedirectResponse
    {
        $redirect = redirect()->route('login');

        if ($request->input('reason') === 'idle') {
            $minutes = (int) config('security.idle_timeout.minutes');

            return $redirect->with('status', "You were signed out after {$minutes} minutes of inactivity.");
        }

        return $redirect;
    }
}
