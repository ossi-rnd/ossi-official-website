<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Idle session timeout
    |--------------------------------------------------------------------------
    |
    | Separate from session.lifetime on purpose: the session lifetime is a
    | storage clean-up window, this is the security control. A payroll screen
    | left open on an unattended terminal must stop being usable.
    |
    | The browser shows a countdown `warning_seconds` before the deadline; the
    | EnforceIdleTimeout middleware is what actually signs the user out.
    |
    */

    'idle_timeout' => [
        'enabled' => (bool) env('SESSION_IDLE_TIMEOUT_ENABLED', true),
        'minutes' => (int) env('SESSION_IDLE_TIMEOUT_MINUTES', 15),
        'warning_seconds' => (int) env('SESSION_IDLE_WARNING_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration throttle
    |--------------------------------------------------------------------------
    |
    | Fortify's register route has no rate limiter, and the form checks an
    | employee number, so without one it could be used to guess numbers.
    |
    */

    'registration' => [
        'max_attempts' => 5,
        'decay_seconds' => 15 * 60,
    ],

];
