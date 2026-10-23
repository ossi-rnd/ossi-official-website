<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The equipment shop: who sees an item (guards, office staff, or everyone) and its photo
     * (private disk, served to signed-in staff only).
     */
    public function up(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->string('audience', 10)->default('all')->after('kind');
            $table->string('photo_path')->nullable()->after('sizes');
        });
    }

    public function down(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->dropColumn(['audience', 'photo_path']);
        });
    }
};
