<?php

namespace App\Actions\Equipment;

use App\Enums\AuditArea;
use App\Enums\EquipmentStatus;
use App\Models\EquipmentIssue;
use App\Models\EquipmentItem;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * An employee asks for an item on My Equipment (an extra uniform, size L). Whoever issues to
 * them is told (EquipmentIssue::issuersFor): Operations for a guard, Admin for office staff.
 * Nothing is charged until it's issued and they authorize the deduction.
 */
final class RequestEquipment
{
    public const MAX_QUANTITY = 5;

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $employee, EquipmentItem $item, ?string $size, int $quantity, ?string $note): EquipmentIssue
    {
        if ($employee->loadMissing('employeeProfile')->employeeProfile === null) {
            throw new AuthorizationException;
        }

        if (! $item->is_active) {
            throw ValidationException::withMessages(['requestItem' => 'That item is no longer given out.']);
        }

        $size = $item->hasSizes() ? $size : null;

        if ($item->hasSizes() && ! in_array($size, $item->sizeList(), true)) {
            throw ValidationException::withMessages(['requestSize' => 'Choose your size.']);
        }

        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw ValidationException::withMessages(['requestQuantity' => 'From 1 to '.self::MAX_QUANTITY.'.']);
        }

        return DB::transaction(function () use ($employee, $item, $size, $quantity, $note) {
            $issue = new EquipmentIssue;
            $issue->forceFill([
                'user_id' => $employee->id,
                'equipment_item_id' => $item->id,
                'label' => $item->name,
                'size' => $size,
                'quantity' => $quantity,
                'unit_price' => ($price = $item->priceFor($size)) === null ? null : number_format($price, 2, '.', ''),
                'charged' => false, // set when it's issued
                'status' => EquipmentStatus::Requested,
                'request_note' => filled($note) ? mb_substr(trim((string) $note), 0, 500) : null,
                'requested_at' => now(),
            ])->save();

            $this->audit->record(AuditArea::Operations, 'equipment.requested', "{$employee->name} asked for {$issue->describe()}", $issue, causer: $employee);

            Notification::send(EquipmentIssue::issuersFor($employee), new UpdateNotice(
                "Equipment request: {$employee->name}",
                $issue->describe().($issue->request_note ? "\nNote: {$issue->request_note}" : ''),
                route('equipment', ['show' => 'requests']), 'package'));

            return $issue;
        });
    }
}
