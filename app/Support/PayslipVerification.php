<?php

namespace App\Support;

use App\Models\PayslipRecord;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The QR code and short code on a printed payslip, so anyone holding the paper (a bank, a landlord)
 * can confirm it came from this system and wasn't changed.
 *
 * The code is an HMAC with the app's secret key over the payslip's identity and its totals (gross,
 * deductions, net). It can't be made up without the key, and if the payslip's figures differ from
 * the ones signed, it no longer verifies. Only a GENERATED (frozen) payslip gets one; an estimate
 * prints as "not an official payslip".
 */
final class PayslipVerification
{
    /** "7KQ2-M9XD-4RTA-PW3C": 16 characters, easy to read out or type. */
    public static function code(PayslipRecord $payslip): string
    {
        $mac = hash_hmac('sha256', self::signed($payslip), (string) config('app.key'), true);

        // Crockford-style base 32 (no I, L, O, U): unambiguous on paper.
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        // 10 bytes = 80 bits = 16 characters of 5 bits each.
        $code = '';
        $buffer = 0;
        $bits = 0;
        foreach (str_split(substr($mac, 0, 10)) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $code .= $alphabet[($buffer >> $bits) & 31];
            }
            $buffer &= (1 << $bits) - 1;
        }

        return implode('-', str_split($code, 4));
    }

    public static function url(PayslipRecord $payslip): string
    {
        return route('payslip.verify', ['payslip' => $payslip->id, 'code' => self::code($payslip)]);
    }

    /** The payslip this code was made for, if it still matches; null for a forged or edited code. */
    public static function find(int $payslipId, string $code): ?PayslipRecord
    {
        $payslip = PayslipRecord::query()->with(['user.employeeProfile', 'payrollRun'])->find($payslipId);
        $given = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $code) ?? '');

        if ($payslip === null || $given === '') {
            return null;
        }

        return hash_equals(str_replace('-', '', self::code($payslip)), $given) ? $payslip : null;
    }

    /** The QR code for the verification link, as inline SVG. */
    public static function qrSvg(PayslipRecord $payslip, int $size = 132): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd));

        return $writer->writeString(self::url($payslip));
    }

    /** What the code vouches for: which payslip, whose, which run, and its totals. */
    private static function signed(PayslipRecord $payslip): string
    {
        return implode('|', [
            'ossi-payslip-v1',
            $payslip->id,
            $payslip->user_id,
            $payslip->payroll_run_id,
            number_format((float) $payslip->gross_pay, 2, '.', ''),
            number_format((float) $payslip->total_deductions, 2, '.', ''),
            number_format((float) $payslip->net_pay, 2, '.', ''),
        ]);
    }
}
