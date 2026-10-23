<?php

namespace App\Models;

use App\Enums\EquipmentKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing on the equipment list (Admin keeps it): a uniform piece, a flashlight, a
 * raincoat… with its price. Radios aren't here: they come from the NTC masterlist.
 * Nothing is fillable: SaveEquipmentItem sets every column.
 *
 * @property int $id
 * @property string $name
 * @property EquipmentKind $kind
 * @property string|null $unit_price
 * @property bool $has_sizes
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class EquipmentItem extends Model
{
    /** The sizes offered when an item has sizes. */
    public const SIZES = ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'has_sizes' => false,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => EquipmentKind::class,
            'unit_price' => 'decimal:2',
            'has_sizes' => 'boolean',
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

    public function priceLabel(): string
    {
        return $this->unit_price === null ? 'No price yet' : '₱'.number_format((float) $this->unit_price, 2);
    }
}
