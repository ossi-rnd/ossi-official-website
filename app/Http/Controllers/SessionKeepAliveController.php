<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnforceIdleTimeout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reading a long payslip makes no HTTP requests, so the browser pings here when
 * it has seen real input. The ping only resets the idle clock when it reports
 * `active: true`; an abandoned tab never keeps a session alive.
 */
final class SessionKeepAliveController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->attributes->set(EnforceIdleTimeout::HEARTBEAT, true);

        if ($request->boolean('active')) {
            $request->session()->put(EnforceIdleTimeout::SESSION_KEY, time());
        }

        $last = (int) $request->session()->get(EnforceIdleTimeout::SESSION_KEY, time());

        return response()->json(['idle_seconds' => time() - $last]);
    }
}
