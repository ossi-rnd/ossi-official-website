<?php

namespace App\Actions\Equipment;

use App\Enums\Ability;
use App\Enums\AuditArea;
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
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Gives an item to someone: from the list, or a radio from the NTC masterlist; new, or
 * answering their request. The issuer (Operations for guards, Admin for office staff) hands it
 * over; money is not theirs to set.
 *
 *  - A uniform is charged (the owner's rule) at its price for that size. Accounting then sets
 *    how much each payslip takes, and that's final (SetUniformCharge, the owner's choice
 *    2026-10-23); the employee authorizes it on My Payslips (Labor Code Art. 113) before any
 *    payslip takes it. Accounting is told here.
 *  - Anything else stays company property: they confirm on My Equipment that they received it
 *    and are accountable for it. Nothing is deducted unless it's later lost or damaged through
 *    their fault, decided after they were heard (DecideEquipmentIncident).
 *
 * A radio issued here is marked in use, with them (and at their post), on the masterlist.
 */
final class IssueEquipment
{
    public const MAX_QUANTITY = 20;

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $issuer, User $employee, ?EquipmentItem $item, ?Radio $radio, ?string $size, int $quantity, ?EquipmentIssue $request = null): EquipmentIssue
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

            if (! $item->isFor($employee)) {
                throw ValidationException::withMessages(['issueItem' => "That item is for {$item->audience->label()}."]);
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

        return DB::transaction(function () use ($issuer, $employee, $item, $radio, $size, $quantity, $request, $charged, $total, $unitPrice) {
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

            if ($radio !== null) {
                $radio->forceFill([
                    'status' => RadioStatus::InUse,
                    'assigned_user_id' => $employee->id,
                    'client_id' => $employee->employeeProfile?->is_office_staff ? null : $employee->employeeProfile?->client_id,
                ])->save();
            }

            $this->audit->record(AuditArea::Operations, 'equipment.issued', "Issued {$issue->describe()} to {$employee->name}".($charged ? ' (charged ₱'.number_format($total, 2).'; Accounting sets the payslip amount)' : ''), $issue, [
                'new' => ['charged' => $charged, 'total' => $charged ? $total : null, 'from_request' => $request !== null],
            ], causer: $issuer);

            UpdateNotice::tell($employee, $issuer, $charged ? 'Uniform issued: confirm you received it' : 'Equipment issued: confirm you received it',
                $issue->describe().($charged
                    ? "\n₱".number_format($total, 2).'. Accounting sets how much each payslip takes; you\'ll be asked to authorize it on My Payslips before anything is deducted. Confirm you received it on My Equipment.'
                    : "\nCompany property. Confirm on My Equipment that you received it."),
                route('my-equipment'), 'package', email: true);

            if ($charged) {
                Notification::send(User::query()->notifiable(Ability::ManagePayroll->roles())->whereKeyNot($issuer->id)->get(), new UpdateNotice(
                    "Uniform charge to set: {$employee->name}",
                    "{$issue->describe()}, ₱".number_format($total, 2).". Issued by {$issuer->name}. Set how much each payslip takes.",
                    route('equipment', ['show' => 'charges']), 'banknotes'));
            }

            return $issue;
        });
    }
}
