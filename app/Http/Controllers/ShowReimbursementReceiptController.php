<?php

namespace App\Http\Controllers;

use App\Enums\Ability;
use App\Models\Reimbursement;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A reimbursement's receipt. Never a public URL: the employee who filed it, the
 * Executive who approves it, and Accounting who pays it.
 */
final class ShowReimbursementReceiptController extends Controller
{
    public function __invoke(Reimbursement $reimbursement): StreamedResponse
    {
        $user = request()->user();
        // The employee, Operations who filed it for a guard, and those who approve and pay.
        abort_unless($user !== null && ($reimbursement->user_id === $user->id || $reimbursement->filed_by === $user->id || $user->can(Ability::ViewReimbursements->value)), 403);

        $disk = Storage::disk((string) config('payroll.concerns.disk'));
        abort_unless($disk->exists($reimbursement->receipt_path), 404);

        return $disk->response($reimbursement->receipt_path, $reimbursement->receipt_name ?? 'receipt', [
            'Content-Type' => $reimbursement->receipt_mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
