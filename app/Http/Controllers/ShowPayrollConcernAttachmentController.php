<?php

namespace App\Http\Controllers;

use App\Enums\Ability;
use App\Models\PayrollConcern;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The photo or PDF attached to a payroll concern. Never a public URL: the
 * employee who reported it sees it, and so does Accounting.
 */
final class ShowPayrollConcernAttachmentController extends Controller
{
    public function __invoke(PayrollConcern $concern): StreamedResponse
    {
        $user = request()->user();
        abort_unless($user !== null && ($concern->user_id === $user->id || $user->can(Ability::ManagePayroll->value)), 403);

        $disk = Storage::disk((string) config('payroll.concerns.disk'));
        abort_unless($concern->attachment_path !== null && $disk->exists($concern->attachment_path), 404);

        return $disk->response($concern->attachment_path, $concern->attachment_name ?? 'attachment', [
            'Content-Type' => (string) $concern->attachment_mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
