<?php

namespace App\Actions\FinalPay;

use App\Actions\Resignations\CompleteResignation;
use App\Enums\AuditArea;
use App\Enums\ClearanceItem;
use App\Enums\DeductionType;
use App\Enums\FinalPayStatus;
use App\Enums\Role;
use App\Models\CashBondEntry;
use App\Models\EmployeeDeduction;
use App\Models\FinalPay;
use App\Models\User;
use App\Notifications\FinalPayNotice;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * A signer signs for their step (FinalPay::signableRoleFor). When Accounting has
 * signed, the President and Vice President are asked; either one's signature releases it:
 *
 *   - the cash bond is refunded (a negative ledger entry) and its deduction stops
 *   - what was owed is settled, as far as the final pay covered it; any rest
 *     stays on the loan as the receivable
 *   - "Final pay released" is ticked on the clearance (which may complete the resignation)
 *   - the employee is told by email, and Accounting
 */
final class SignFinalPay
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CompleteResignation $complete,
    ) {}

    public function handle(FinalPay $finalPay, User $signer): FinalPay
    {
        return DB::transaction(function () use ($finalPay, $signer) {
            $locked = FinalPay::query()->with(['user', 'resignation.user.employeeProfile'])->lockForUpdate()->findOrFail($finalPay->id);
            $role = $locked->signableRoleFor($signer);

            if ($role === null) {
                throw ValidationException::withMessages(['finalPay' => $locked->status === FinalPayStatus::Signing && ! $locked->readyForLeadership() && FinalPay::holdsStep($signer, Role::President)
                    ? 'The President or Vice President signs last, after Accounting.'
                    : 'There is nothing for you to sign here.']);
            }

            $signoffs = $locked->signoffs ?? [];
            $signoffs[$role->value] = ['by' => $signer->name, 'by_id' => $signer->id, 'at' => now()->toIso8601String()];
            $locked->forceFill(['signoffs' => $signoffs])->save();

            $this->audit->record(AuditArea::Payroll, 'final_pay.signed', "Signed {$locked->user->name}'s final pay as ".FinalPay::signerLabel($role), $locked, causer: $signer);

            if ($role === Role::President) {
                return $this->release($locked, $signer);
            }

            // Everyone before the leadership is done: now the President or Vice President.
            if ($locked->readyForLeadership()) {
                Notification::send(SubmitFinalPay::holders(Role::leadership(), $locked), new FinalPayNotice($locked, FinalPayNotice::TO_SIGN));
            }

            return $locked;
        });
    }

    private function release(FinalPay $finalPay, User $executive): FinalPay
    {
        $finalPay->forceFill(['status' => FinalPayStatus::Released, 'released_at' => now()])->save();

        // The cash bond goes back to them, and stops being taken.
        if ((float) $finalPay->cash_bond_refund > 0) {
            $refund = new CashBondEntry;
            $refund->forceFill([
                'user_id' => $finalPay->user_id,
                'kind' => CashBondEntry::REFUND,
                'amount' => number_format(-(float) $finalPay->cash_bond_refund, 2, '.', ''),
                'note' => 'Refunded in final pay',
                'recorded_by' => $executive->id,
                'final_pay_id' => $finalPay->id,
            ])->save();
        }

        EmployeeDeduction::query()->where('user_id', $finalPay->user_id)->where('type', DeductionType::CashBond)->update(['is_active' => false]);

        // What was owed: settled up to what the pay side covered; the rest stays as the receivable.
        $cover = max(0.0, (float) $finalPay->total_pay - $this->negativeAdjustments($finalPay));

        foreach ($finalPay->lines ?? [] as $line) {
            // Only lines for a loan (they carry its id); adjustments that take money off have none.
            if ($line['kind'] !== 'deduct' || ! isset($line['deduction_id']) || $cover <= 0) {
                continue;
            }

            $deduction = EmployeeDeduction::query()->where('user_id', $finalPay->user_id)->whereKey($line['deduction_id'])->lockForUpdate()->first();

            if ($deduction === null || $deduction->remaining_balance === null) {
                continue;
            }

            $taken = min($cover, (float) $deduction->remaining_balance);
            $left = round((float) $deduction->remaining_balance - $taken, 2);
            $deduction->forceFill(['remaining_balance' => number_format($left, 2, '.', ''), 'is_active' => $left > 0])->save();
            $cover -= $taken;
        }

        // Ticked on the clearance, which may complete the resignation.
        $resignation = $finalPay->resignation;
        $clearance = $resignation->clearance ?? [];
        $clearance[ClearanceItem::FinalPay->value] = ['at' => now()->toIso8601String(), 'by' => "{$executive->name} (final pay released)"];
        $resignation->forceFill(['clearance' => $clearance])->save();

        $this->audit->record(AuditArea::Payroll, 'final_pay.released', "Released {$finalPay->user->name}'s final pay: net ₱".number_format((float) $finalPay->net, 2), $finalPay, causer: $executive);
        $this->complete->handle($resignation);

        $finalPay->user->notify(new FinalPayNotice($finalPay, FinalPayNotice::RELEASED_TO_EMPLOYEE));
        Notification::send(SubmitFinalPay::holders([Role::Accounting], $finalPay), new FinalPayNotice($finalPay, FinalPayNotice::RELEASED));

        return $finalPay;
    }

    /** Adjustments that take off money: they use the pay side before what's owed does. */
    private function negativeAdjustments(FinalPay $finalPay): float
    {
        return array_sum(array_map(fn (array $adjustment) => $adjustment['amount'] < 0 ? abs($adjustment['amount']) : 0.0, $finalPay->adjustments ?? []));
    }
}
