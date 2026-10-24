<?php

namespace App\Http\Controllers;

use App\Support\PayslipVerification;
use Illuminate\Http\Response;

/**
 * Where a printed payslip's QR code leads, without signing in: confirms it came from this system
 * and shows its essentials (who, which period, gross, deductions, net) to compare with the paper.
 * No government IDs and no line-by-line detail. A made-up or altered code finds nothing.
 * Not indexed by search engines; rate-limited by the route.
 */
final class VerifyPayslipController extends Controller
{
    public function __invoke(int $payslip, string $code): Response
    {
        $record = PayslipVerification::find($payslip, $code);

        return response()
            ->view('payslips.verify', ['record' => $record, 'code' => strtoupper($code)], $record ? 200 : 404)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store');
    }
}
