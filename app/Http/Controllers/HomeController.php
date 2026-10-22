<?php

namespace App\Http\Controllers;

use App\Enums\Ability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Fortify's `home` (config/fortify.php). Sends each person to their starting page.
 */
final class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        return $request->user()?->can(Ability::AccessEmployerArea->value)
            ? redirect()->route('dashboard')
            : redirect()->route('attendance');
    }
}
