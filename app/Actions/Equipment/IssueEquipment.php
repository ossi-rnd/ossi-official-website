<?php

namespace App\Actions\Equipment;

use App\Actions\Payroll\SaveEmployeeDeduction;
use App\Enums\AuditArea;
use App\Enums\DeductionAuthorizationBasis;
use App\Enums\DeductionType;
use App\Enums\EquipmentKind;
use App\Enums\EquipmentStatus;
use App\Enums\RadioStatus;
use App\Models\EquipmentIssue;
use App\Models\EquipmentItem;
use App\Models\Radio;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives an item to someone: from the list, or a radio from the NTC masterlist; new, or
 * answering their request.
 *
 *  - A uniform is charged (the owner's rule): its price becomes a deduction the employee must
 *    authorize on My Payslips (Labor Code Art. 113) before any payslip takes it.
 *  - Anything else stays company property: they confirm on My Equipment that they received it
 *    and are accountable for it. Nothing is deducted unless it's later lost or damaged through
 *    their fault, decided after they were heard (DecideEquipmentIncident).
 *
 * A radio issued here is marked in use, with them (and at their post), on the masterlist.
 */
final class IssueEquipment
{
    public const MAX_QUANTITY = 20;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SaveEmployeeDeduction $saveDeduction,
    ) {}

    public function handle(User $issuer, User $employee, ?EquipmentItem $item, ?Radio $radio, ?string $size, int $quantity, ?float $installment, ?EquipmentIssue $request = null): EquipmentIssue
    {
        if (! EquipmentIssue::canIssueTo($issuer, $employee)) {
            throw new AuthorizationException;
        }

        if (($item === null) === ($radio === null)) {
            throw ValidationException::withMessages(['issueItem' => 'Choose an item or a radio.']);
        }

        if ($request !== null && ($request->user_id !== $employee->id || $request->status !== EquipmentStatus::Requested)) {
            throw ValidationException::withMessages(['issueItem' => 'That request was already answered.']);
        }

        if ($item !== null) {
            if (! $item->is_active) {
                throw ValidationException::withMessages(['issueItem' => 'That item is no longer given out.']);
            }

            $size = $item->hasSizes() ? $size : null;

            if ($item->hasSizes() && ! in_array($size, $item->sizeList(), true)) {
                throw ValidationException::withMessages(['issueSize' => 'Choose the size.']);
            }

            if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
                throw ValidationException::withMessages(['issueQuantity' => 'From 1 to '.self::MAX_QUANTITY.'.']);
            }
        } else {
            $size = null;
            $quantity = 1;

            if ($radio?->status !== RadioStatus::Spare) {
                throw ValidationException::withMessages(['issueRadio' => 'Only a radio in stock (Spare) can be issued.']);
            }
        }

        $charged = $item?->kind === EquipmentKind::Uniform;
        $unitPrice = $item?->priceFor($size);
        $total = $charged ? round((float) $unitPrice * $quantity, 2) : 0.0;

        if ($charged && $total <= 0) {
            throw ValidationException::withMessages(['issueItem' => 'Accounting hasn\'t set this uniform\'s price'.($size ? " for size {$size}" : '').' yet.']);
        }

        if ($charged && ($installment === null || $installment <= 0 || $installment > $total)) {
            throw ValidationException::withMessages(['issueInstallment' => 'Per payslip: more than ₱0 and at most the total, ₱'.number_format($total, 2).'.']);
        }

        return DB::transaction(function () use ($issuer, $employee, $item, $radio, $size, $quantity, $installment, $request, $charged, $total, $unitPrice) {
            $issue = $request ?? new EquipmentIssue;
            $label = $radio !== null ? "{$radio->brand} {$radio->model} radio (SN {$radio->serial_number})" : $item->name;

            $issue->forceFill([
                'user_id' => $employee->id,
                'equipment_item_id' => $item?->id,
                'radio_id' => $radio?->id,
                'label' => $label,
                'size' => $size,
                'quantity' => $quantity,
                // The price for that size, as it is today: a later price change doesn't touch it.
                'unit_price' => $unitPrice === null ? null : number_format($unitPrice, 2, '.', ''),
                'charged' => $charged,
                'status' => EquipmentStatus::Issued,
                'issued_by' => $issuer->id,
                'issued_at' => now(),
            ])->save();

            if ($charged) {
                // Their authority to deduct, asked on My Payslips; nothing is taken until they give it.
                $deduction = $this->saveDeduction->handle($employee, $issue->describe(), DeductionType::Uniform, (float) $installment, $total, $issuer, DeductionAuthorizationBasis::Employee);
                $deduction->forceFill(['remaining_balance' => number_format($total, 2, '.', '')])->save();
                $issue->forceFill(['employee_deduction_id' => $deduction->id])->save();
            }

            if ($radio !== null) {
                $radio->forceFill([
                    'status' => RadioStatus::InUse,
                    'assigned_user_id' => $employee->id,
                    'client_id' => $employee->employeeProfile?->is_office_staff ? null : $employee->employeeProfile?->client_id,
                ])->save();
            }

            $this->audit->record(AuditArea::Operations, 'equipment.issued', "Issued {$issue->describe()} to {$employee->name}".($charged ? ' (charged ₱'.number_format($total, 2).', authority to deduct asked)' : ''), $issue, [
                'new' => ['charged' => $charged, 'total' => $charged ? $total : null, 'installment' => $charged ? $installment : null, 'from_request' => $request !== null],
            ], causer: $issuer);

            UpdateNotice::tell($employee, $issuer, $charged ? 'Uniform issued: confirm and authorize' : 'Equipment issued: confirm you received it',
                $issue->describe().($charged
                    ? "\n₱".number_format($total, 2).', ₱'.number_format((float) $installment, 2).' per payslip once you authorize it on My Payslips. Confirm you received it on My Equipment.'
                    : "\nCompany property. Confirm on My Equipment that you received it."),
                route('my-equipment'), 'package', email: true);

            return $issue;
        });
    }
}
