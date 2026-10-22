<?php

namespace App\Http\Controllers;

use App\Actions\Auth\AcknowledgePrivacyNotice;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AcknowledgePrivacyNoticeController extends Controller
{
    public function __invoke(Request $request, AcknowledgePrivacyNotice $acknowledge): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $acknowledge->handle($user);

        return redirect()->intended(route('home'))->with('status', 'Thank you. Your acknowledgement has been recorded.');
    }
}
