<?php

namespace App\Http\Controllers;

use App\Models\PettyCashExpense;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A petty cash receipt. Never a public URL: the fund's custodian, and those who see every fund.
 */
final class ShowPettyCashReceiptController extends Controller
{
    public function __invoke(PettyCashExpense $expense): StreamedResponse
    {
        $user = request()->user();
        $expense->loadMissing('fund');
        abort_unless($user !== null && $expense->fund->canBeSeenBy($user), 403);

        $disk = Storage::disk((string) config('payroll.concerns.disk'));
        abort_unless($disk->exists($expense->receipt_path), 404);

        return $disk->response($expense->receipt_path, $expense->receipt_name ?? 'receipt', [
            'Content-Type' => $expense->receipt_mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
