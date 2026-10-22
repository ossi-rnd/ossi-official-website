<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-service that only makes sense for someone paid through this payroll
 * (clocking in, leave, overtime, payslips, borrowed load, complaints). Roles
 * outside it (Role::withoutEmployeeRecord(): the Super Admin, President and Vice President)
 * don't see the links, and this stops a typed URL too.
 */
final class EnsureOnPayroll
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user instanceof User && $user->isPaidOutsidePayroll(), 403, 'Your account isn\'t an employee account on this payroll, so there\'s no attendance or pay here for you.');

        return $next($request);
    }
}
