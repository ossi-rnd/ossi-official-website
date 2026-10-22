<?php

namespace App\Http\Controllers;

use App\Enums\Ability;
use App\Models\LoadRequest;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The receipt for a borrowed load: the approver's screenshot of the load
 * confirmation. Never a public URL. The employee who borrowed sees it, and
 * so do the Admins who handle loads and Accounting (a disputed deduction).
 */
final class ShowLoadProofController extends Controller
{
    public function __invoke(LoadRequest $loadRequest): StreamedResponse
    {
        $user = request()->user();
        abort_unless($user !== null && (
            $loadRequest->user_id === $user->id
            || $user->can(Ability::ManageLoadRequests->value)
            || $user->can(Ability::ManagePayroll->value)
        ), 403);

        $disk = Storage::disk((string) config('load.disk'));
        abort_unless($loadRequest->proof_path !== null && $disk->exists($loadRequest->proof_path), 404);

        return $disk->response($loadRequest->proof_path, 'load-receipt-'.$loadRequest->id.($loadRequest->proof_mime === 'image/png' ? '.png' : '.jpg'), [
            'Content-Type' => (string) $loadRequest->proof_mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
