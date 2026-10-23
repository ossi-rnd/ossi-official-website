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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * An employee sends their cart from the equipment shop (My Equipment): one or more items, each
 * with its size and quantity. Each becomes a request their issuers answer (issue it, or not);
 * the issuers get one bell for the whole cart (EquipmentIssue::issuersFor: Operations for a
 * guard, Admin for office staff). Nothing is charged until a uniform is issued and they
 * authorize the deduction.
 */
final class RequestEquipment
{
    public const MAX_QUANTITY = 5;

    public const MAX_LINES = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<array{item: EquipmentItem, size: string|null, quantity: int}>  $lines
     * @return Collection<int, EquipmentIssue>
     */
    public function handle(User $employee, array $lines, ?string $note): Collection
    {
        if ($employee->loadMissing('employeeProfile')->employeeProfile === null) {
            throw new AuthorizationException;
        }

        if ($lines === [] || count($lines) > self::MAX_LINES) {
            throw ValidationException::withMessages(['cart' => $lines === [] ? 'Your cart is empty.' : 'At most '.self::MAX_LINES.' items in one request.']);
        }

        foreach ($lines as $line) {
            self::check($employee, $line['item'], $line['size'], $line['quantity']);
        }

        $note = filled($note) ? mb_substr(trim((string) $note), 0, 500) : null;

        return DB::transaction(function () use ($employee, $lines, $note) {
            $created = [];

            foreach ($lines as ['item' => $item, 'size' => $size, 'quantity' => $quantity]) {
                $size = $item->hasSizes() ? $size : null;
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
                    'request_note' => $note,
                    'requested_at' => now(),
                ])->save();

                $this->audit->record(AuditArea::Operations, 'equipment.requested', "{$employee->name} asked for {$issue->describe()}", $issue, causer: $employee);
                $created[] = $issue;
            }

            Notification::send(EquipmentIssue::issuersFor($employee), new UpdateNotice(
                "Equipment request: {$employee->name}",
                implode("\n", array_map(fn (EquipmentIssue $issue) => $issue->describe(), $created)).($note ? "\nNote: {$note}" : ''),
                route('equipment', ['show' => 'requests']), 'package'));

            return new Collection($created);
        });
    }

    /** One cart line: still given out, for this person, a size it comes in, a sensible quantity. */
    public static function check(User $employee, EquipmentItem $item, ?string $size, int $quantity): void
    {
        if (! $item->is_active || ! $item->isFor($employee)) {
            throw ValidationException::withMessages(['cart' => "{$item->name} isn't available to you."]);
        }

        if ($item->hasSizes() && ! in_array($size, $item->sizeList(), true)) {
            throw ValidationException::withMessages(['cart' => "Choose your size for {$item->name}."]);
        }

        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw ValidationException::withMessages(['cart' => "{$item->name}: from 1 to ".self::MAX_QUANTITY.'.']);
        }
    }
}
