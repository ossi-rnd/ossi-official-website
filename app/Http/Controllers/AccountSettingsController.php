<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Plain Blade forms posting to Fortify's own routes (profile information,
 * password, two-factor). Fortify then does the validation, hashing and
 * re-verification of a changed email; we only render the page.
 */
final class AccountSettingsController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('account.settings', ['user' => $request->user()]);
    }
}
