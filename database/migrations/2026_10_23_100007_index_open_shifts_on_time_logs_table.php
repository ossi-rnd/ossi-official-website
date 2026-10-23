<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shifts still open ("clock_out_at is null") are looked up on every Attendance Log page and by
     * the attendance alerts every 5 minutes. Without an index that scans every time log: 88 ms at
     * 5,000 guards (65,000 rows) in the load test, growing every day.
     */
    public function up(): void
    {
        Schema::table('time_logs', function (Blueprint $table) {
            $table->index('clock_out_at');
        });
    }

    public function down(): void
    {
        Schema::table('time_logs', function (Blueprint $table) {
            $table->dropIndex(['clock_out_at']);
        });
    }
};
