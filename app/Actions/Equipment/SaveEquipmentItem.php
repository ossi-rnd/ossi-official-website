<?php

namespace App\Actions\Equipment;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\EquipmentKind;
use App\Models\EquipmentItem;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Adds or edits an item on the equipment list (Admin): its name, whether it's a uniform
 * (charged) or company property, the sizes it comes in (standard or custom), and whether it's
 * still given out. Prices are Accounting's (SetEquipmentItemPrices): a size kept keeps its
 * price; a new one starts at the item's price. An item no longer given out is switched off,
 * not deleted, so past records keep it. Audited with the changes.
 */
final class SaveEquipmentItem
{
    /** Letters, numbers, spaces, dots, dashes and slashes: "XL", "42", "W32/L30", "Free size". */
    public const SIZE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 .\/\-]{0,9}$/';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<string>  $sizes
     */
    public function handle(?EquipmentItem $item, string $name, EquipmentKind $kind, array $sizes, bool $isActive, User $actor): EquipmentItem
    {
        if (! $actor->can(Ability::ManageEquipmentItems->value)) {
            throw new AuthorizationException;
        }

        $name = trim($name);

        if (EquipmentItem::query()->where('name', $name)->when($item, fn ($query, EquipmentItem $existing) => $query->whereKeyNot($existing->id))->exists()) {
            throw ValidationException::withMessages(['itemName' => "\"{$name}\" is already on the list."]);
        }

        $sizes = self::cleanSizes($sizes);

        return DB::transaction(function () use ($item, $name, $kind, $sizes, $isActive, $actor) {
            $isNew = $item === null;
            $item ??= new EquipmentItem;
            $snapshot = fn (EquipmentItem $i) => ['name' => $i->name, 'kind' => $i->kind->value, 'sizes' => implode(', ', $i->sizeList()), 'is_active' => $i->is_active];
            $before = $isNew ? [] : $snapshot($item);

            // A size kept keeps its own price.
            $kept = collect($item->sizes ?? [])->keyBy('size');
            $item->forceFill([
                'name' => $name,
                'kind' => $kind,
                'sizes' => $sizes === [] ? null : array_map(fn (string $size) => ['size' => $size, 'price' => $kept->get($size)['price'] ?? null], $sizes),
                'is_active' => $isActive,
            ])->save();

            $this->audit->record(AuditArea::Operations, $isNew ? 'equipment_item.created' : 'equipment_item.updated', "Saved equipment item \"{$item->name}\"", $item,
                AuditLogger::diff($before, $snapshot($item->refresh())), causer: $actor);

            // A uniform (or a new size of one) without a price can't be issued: Accounting is told.
            if ($item->is_active && $item->needsPrice()) {
                Notification::send(User::query()->notifiable(Ability::SetEquipmentPrices->roles())->whereKeyNot($actor->id)->get(),
                    new UpdateNotice("Uniform needs a price: {$item->name}", ($item->hasSizes() ? 'Sizes: '.implode(', ', $item->sizeList()).".\n" : '').'It can\'t be issued until it has one.', route('equipment'), 'package'));
            }

            return $item;
        });
    }

    /**
     * Trimmed, standard sizes in upper case, no duplicates (whatever the case), in the order given.
     *
     * @param  list<string>  $sizes
     * @return list<string>
     */
    public static function cleanSizes(array $sizes): array
    {
        $clean = [];

        foreach ($sizes as $size) {
            $size = preg_replace('/\s+/', ' ', trim($size)) ?? '';

            if ($size === '') {
                continue;
            }

            if (in_array(strtoupper($size), EquipmentItem::STANDARD_SIZES, true)) {
                $size = strtoupper($size);
            }

            if (preg_match(self::SIZE_PATTERN, $size) !== 1) {
                throw ValidationException::withMessages(['customSize' => "\"{$size}\": up to 10 letters or numbers (spaces, dots, dashes and slashes allowed)."]);
            }

            if (! in_array(strtolower($size), array_map('strtolower', $clean), true)) {
                $clean[] = $size;
            }
        }

        if (count($clean) > EquipmentItem::MAX_SIZES) {
            throw ValidationException::withMessages(['customSize' => 'At most '.EquipmentItem::MAX_SIZES.' sizes.']);
        }

        return $clean;
    }
}
