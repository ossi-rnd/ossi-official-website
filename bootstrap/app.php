<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\PreventAuthenticatedPageCaching;
use App\Http\Middleware\RequirePrivacyAcknowledgement;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Laravel Cloud (and most PaaS) terminate TLS at a load balancer.
        $middleware->trustProxies(at: '*');

        // Appended, so they run after StartSession. The web group also wraps
        // Livewire's update endpoint, so these guard every wire:click too.
        $middleware->web(append: [
            EnforceIdleTimeout::class,
            EnsureAccountIsActive::class,
            // After the idle check: a timed-out session should be signed out, not sent to the notice.
            RequirePrivacyAcknowledgement::class,
            PreventAuthenticatedPageCaching::class,
            AddSecurityHeaders::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
