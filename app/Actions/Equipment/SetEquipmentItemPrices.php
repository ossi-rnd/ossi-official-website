<?php

namespace App\Actions\Equipment;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\EquipmentItem;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounting sets what an item costs: its price, and optionally a size's own price (null: the
 * item's). Only what's issued from now on uses a new price; what was already issued keeps the
 * price it was issued at. Audited with the changes.
 */
final class SetEquipmentItemPrices
{
    public const MAX_PRICE = 999999;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, float|null>  $sizePrices  size => its own price, or null for the item's
     */
    public function handle(EquipmentItem $item, ?float $price, array $sizePrices, User $actor): EquipmentItem
    {
        if (! $actor->can(Ability::SetEquipmentPrices->value)) {
            throw new AuthorizationException;
        }

        foreach (['basePrice' => $price, ...$sizePrices] as $field => $amount) {
            if ($amount !== null && ($amount < 0 || $amount > self::MAX_PRICE)) {
                throw ValidationException::withMessages([$field === 'basePrice' ? 'basePrice' : 'sizePrices' => 'From ₱0 to ₱'.number_format(self::MAX_PRICE).'.']);
            }
        }

        $unknown = array_diff(array_map('strval', array_keys($sizePrices)), $item->sizeList());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['sizePrices' => 'Not a size of this item: '.implode(', ', $unknown).'.']);
        }

        return DB::transaction(function () use ($item, $price, $sizePrices, $actor) {
            $snapshot = fn (EquipmentItem $i) => ['price' => $i->unit_price, ...collect($i->sizes ?? [])->mapWithKeys(fn (array $entry) => ["size {$entry['size']}" => $entry['price']])->all()];
            $before = $snapshot($item);

            $item->forceFill([
                'unit_price' => $price === null ? null : number_format($price, 2, '.', ''),
                'sizes' => $item->sizes === null ? null : array_map(fn (array $entry) => [
                    'size' => $entry['size'],
                    'price' => array_key_exists($entry['size'], $sizePrices) && $sizePrices[$entry['size']] !== null ? round((float) $sizePrices[$entry['size']], 2) : null,
                ], $item->sizes),
            ])->save();

            $this->audit->record(AuditArea::Operations, 'equipment_item.priced', "Set the price of \"{$item->name}\"", $item,
                AuditLogger::diff($before, $snapshot($item->refresh())), causer: $actor);

            return $item;
        });
    }
}
