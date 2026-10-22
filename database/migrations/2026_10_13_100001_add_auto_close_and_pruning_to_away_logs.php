<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keeping time away for the record, without keeping where people were forever:
     *  - auto_closed_at: an absence nobody ended (no "Back", no clock-out) is
     *    closed at the end of the duty, and marked so HR can follow up;
     *  - locations_pruned_at: when its GPS trail was deleted under the
     *    retention policy (the reason, times and approval stay).
     */
    public function up(): void
    {
        Schema::table('away_logs', function (Blueprint $table) {
            $table->timestamp('auto_closed_at')->nullable()->after('returned_distance_meters');
            $table->timestamp('locations_pruned_at')->nullable()->after('auto_closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('away_logs', function (Blueprint $table) {
            $table->dropColumn(['auto_closed_at', 'locations_pruned_at']);
        });
    }
};
