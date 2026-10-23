<?php

namespace App\Actions\Equipment;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\EquipmentKind;
use App\Models\EquipmentItem;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds or edits an item on the equipment list (Admin): its name, whether it's a uniform
 * (charged) or company property, its price, and whether it comes in sizes. An item no longer
 * given out is switched off, not deleted, so past records keep it. Audited with the changes.
 */
final class SaveEquipmentItem
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(?EquipmentItem $item, string $name, EquipmentKind $kind, ?float $unitPrice, bool $hasSizes, bool $isActive, User $actor): EquipmentItem
    {
        if (! $actor->can(Ability::ManageEquipmentItems->value)) {
            throw new AuthorizationException;
        }

        $name = trim($name);

        if (EquipmentItem::query()->where('name', $name)->when($item, fn ($query, EquipmentItem $existing) => $query->whereKeyNot($existing->id))->exists()) {
            throw ValidationException::withMessages(['itemName' => "\"{$name}\" is already on the list."]);
        }

        // A uniform is charged, so it needs its price.
        if ($kind === EquipmentKind::Uniform && ($unitPrice === null || $unitPrice <= 0)) {
            throw ValidationException::withMessages(['itemPrice' => 'A uniform is charged to the employee: enter its price.']);
        }

        return DB::transaction(function () use ($item, $name, $kind, $unitPrice, $hasSizes, $isActive, $actor) {
            $isNew = $item === null;
            $item ??= new EquipmentItem;
            $snapshot = fn (EquipmentItem $i) => ['name' => $i->name, 'kind' => $i->kind->value, 'unit_price' => $i->unit_price, 'has_sizes' => $i->has_sizes, 'is_active' => $i->is_active];
            $before = $isNew ? [] : $snapshot($item);

            $item->forceFill([
                'name' => $name,
                'kind' => $kind,
                'unit_price' => $unitPrice === null ? null : number_format($unitPrice, 2, '.', ''),
                'has_sizes' => $hasSizes,
                'is_active' => $isActive,
            ])->save();

            $this->audit->record(AuditArea::Operations, $isNew ? 'equipment_item.created' : 'equipment_item.updated', "Saved equipment item \"{$item->name}\"", $item,
                AuditLogger::diff($before, $snapshot($item->refresh())), causer: $actor);

            return $item;
        });
    }
}
