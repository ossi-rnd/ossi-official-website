<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Readable by anyone, so the registration page can link to it BEFORE any
 * personal data is collected. Plain Blade, not Livewire: it is the one page
 * RequirePrivacyAcknowledgement lets a not-yet-acknowledged user see.
 */
final class PrivacyNoticeController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('privacy.notice', [
            'sections' => config('privacy.sections'),
            'controller' => config('privacy.controller'),
            'officer' => config('privacy.officer'),
            'commission' => config('privacy.commission'),
            'version' => config('privacy.version'),
            'needsAcknowledgement' => $user instanceof User && ! $user->hasAcknowledgedPrivacyNotice(),
        ]);
    }
}
