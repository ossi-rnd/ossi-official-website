<?php

namespace App\Actions\FinalPay;

use App\Enums\AuditArea;
use App\Enums\FinalPayStatus;
use App\Enums\Role;
use App\Models\FinalPay;
use App\Models\User;
use App\Notifications\FinalPayNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Either signer (Accounting, the President or Vice President) sends a statement back with a reason: it becomes a
 * draft again and every signature so far is cleared, since what they signed
 * may change. Accounting is told.
 */
final class ReturnFinalPay
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(FinalPay $finalPay, User $signer, string $note): FinalPay
    {
        if (! array_filter(FinalPay::SIGNERS, fn (Role $role) => FinalPay::holdsStep($signer, $role))) {
            throw new AuthorizationException;
        }

        if (mb_strlen(trim($note)) < 5) {
            throw ValidationException::withMessages(['returnNote' => 'Say what needs changing.']);
        }

        return DB::transaction(function () use ($finalPay, $signer, $note) {
            $locked = FinalPay::query()->with('user')->lockForUpdate()->findOrFail($finalPay->id);

            if ($locked->status !== FinalPayStatus::Signing || $locked->user_id === $signer->id) {
                throw ValidationException::withMessages(['returnNote' => 'Only a statement being signed can be sent back.']);
            }

            $locked->forceFill(['status' => FinalPayStatus::Draft, 'signoffs' => null, 'return_note' => trim($note), 'returned_by' => $signer->id])->save();
            $this->audit->record(AuditArea::Payroll, 'final_pay.returned', "Sent {$locked->user->name}'s final pay back: ".trim($note), $locked, causer: $signer);

            Notification::send(SubmitFinalPay::holders([Role::Accounting], $locked), new FinalPayNotice($locked, FinalPayNotice::RETURNED));

            return $locked;
        });
    }
}
