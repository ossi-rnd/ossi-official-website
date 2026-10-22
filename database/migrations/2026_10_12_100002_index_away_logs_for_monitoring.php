<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The away monitors, the sidebar badges and the 15-minute alerts all ask
     * "who is out right now" (returned_at IS NULL, by left_at), and the
     * "back today" list asks by left_at. The (user_id, returned_at) index
     * can't answer either without a user, so with a year of absences every
     * page load would read the whole table.
     */
    public function up(): void
    {
        Schema::table('away_logs', function (Blueprint $table) {
            $table->index(['returned_at', 'left_at']);
            $table->index('left_at');
        });
    }

    public function down(): void
    {
        Schema::table('away_logs', function (Blueprint $table) {
            $table->dropIndex(['returned_at', 'left_at']);
            $table->dropIndex(['left_at']);
        });
    }
};
