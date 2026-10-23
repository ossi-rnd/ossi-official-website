<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Replaced by each item's own list of sizes (`sizes`, migration 2026_10_23_100002). */
    public function up(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->dropColumn('has_sizes');
        });
    }

    public function down(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->boolean('has_sizes')->default(false)->after('unit_price');
        });
    }
};
