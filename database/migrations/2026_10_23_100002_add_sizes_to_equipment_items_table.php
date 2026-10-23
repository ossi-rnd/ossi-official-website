<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each item's own sizes (standard XS to 5XL, or custom: shoe 38 to 45, waist 28 to 40, "Free size"),
     * each with an optional price of its own (null: the item's price). A list of
     * {"size": "L", "price": null}. Items that had "comes in sizes" get XS to 3XL, as before.
     */
    public function up(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->json('sizes')->nullable()->after('unit_price');
        });

        $standard = json_encode(array_map(fn (string $size) => ['size' => $size, 'price' => null], ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL']));
        DB::table('equipment_items')->where('has_sizes', true)->update(['sizes' => $standard]);
    }

    public function down(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->dropColumn('sizes');
        });
    }
};
