<?php

namespace App\Models;

use App\Enums\EquipmentAudience;
use App\Enums\EquipmentKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing on the equipment list: a uniform piece, a flashlight, a raincoat… Admin keeps the
 * items and their sizes (standard XS to 5XL, or custom: shoe 38 to 45, waist 28 to 40, "Free
 * size"); Accounting sets the prices: the item's, and optionally one per size (a 3XL costing
 * more). Radios aren't here: they come from the NTC masterlist. Nothing is fillable:
 * SaveEquipmentItem and SetEquipmentItemPrices set every column.
 *
 * @property int $id
 * @property string $name
 * @property EquipmentKind $kind
 * @property EquipmentAudience $audience who sees it in the shop and can be issued it
 * @property string|null $unit_price the item's price; a size may have its own
 * @property list<array{size: string, price: float|null}>|null $sizes
 * @property string|null $photo_path on the private photo disk; served by ShowEquipmentPhotoController
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class EquipmentItem extends Model
{
    /** The sizes Admin ticks from; anything else is added as a custom size. */
    public const STANDARD_SIZES = ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL'];

    public const MAX_SIZES = 30;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'audience' => 'all',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => EquipmentKind::class,
            'audience' => EquipmentAudience::class,
            'unit_price' => 'decimal:2',
            'sizes' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<EquipmentItem>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The items this person sees in the shop (and can be issued): guards get guard items and
     * shared ones; office staff, office items and shared ones.
     *
     * @param  Builder<EquipmentItem>  $query
     */
    public function scopeFor(Builder $query, User $person): void
    {
        $query->whereIn('audience', array_map(fn (EquipmentAudience $audience) => $audience->value,
            array_filter(EquipmentAudience::cases(), fn (EquipmentAudience $audience) => $audience->includes(self::isOfficeSide($person)))));
    }

    public function isFor(User $person): bool
    {
        return $this->audience->includes(self::isOfficeSide($person));
    }

    /** No deployment record counts as office (as everywhere else, User::isOfficeStaff). */
    private static function isOfficeSide(User $person): bool
    {
        return $person->isOfficeStaff();
    }

    /** The photo's URL, with a version so a new photo isn't served from the browser's cache. */
    public function photoUrl(): ?string
    {
        return $this->photo_path === null ? null : route('equipment.photo', ['item' => $this->id, 'v' => hash('xxh3', $this->photo_path)]);
    }

    /**
     * @return list<string>
     */
    public function sizeList(): array
    {
        return array_map(fn (array $entry) => $entry['size'], $this->sizes ?? []);
    }

    public function hasSizes(): bool
    {
        return $this->sizeList() !== [];
    }

    /** What one costs in that size: its own price, else the item's; null when none is set. */
    public function priceFor(?string $size): ?float
    {
        foreach ($this->sizes ?? [] as $entry) {
            if ($entry['size'] === $size && $entry['price'] !== null) {
                return (float) $entry['price'];
            }
        }

        return $this->unit_price === null ? null : (float) $this->unit_price;
    }

    /** A uniform is charged, so every size it comes in needs a price before it can be issued. */
    public function needsPrice(): bool
    {
        if ($this->kind !== EquipmentKind::Uniform) {
            return false;
        }

        $sizes = $this->hasSizes() ? $this->sizeList() : [null];

        return collect($sizes)->contains(fn (?string $size) => ($this->priceFor($size) ?? 0.0) <= 0);
    }

    /** "₱450.00", "₱450.00 to ₱520.00", or "No price yet". */
    public function priceLabel(?string $size = null): string
    {
        $peso = fn (float $amount) => '₱'.number_format($amount, 2);

        if ($size !== null) {
            $price = $this->priceFor($size);

            return $price === null ? 'No price yet' : $peso($price);
        }

        $prices = collect($this->hasSizes() ? $this->sizeList() : [null])->map(fn (?string $s) => $this->priceFor($s))->filter(fn (?float $p) => $p !== null);

        return match (true) {
            $prices->isEmpty() => 'No price yet',
            $prices->min() === $prices->max() => $peso((float) $prices->min()),
            default => $peso((float) $prices->min()).' to '.$peso((float) $prices->max()),
        };
    }

    /** Active uniforms still waiting for a price from Accounting (their badge). */
    public static function needingPriceCount(): int
    {
        return self::query()->active()->where('kind', EquipmentKind::Uniform)->get()->filter(fn (self $item) => $item->needsPrice())->count();
    }
}
