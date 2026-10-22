<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the employee confirmed before sending their resignation (notice,
     * withdrawal, clearance, final pay), saved word for word with the time, so
     * the record shows exactly what they agreed to even if the wording changes.
     * Null for a paper letter HR entered (the paper is the record).
     */
    public function up(): void
    {
        Schema::table('resignations', function (Blueprint $table) {
            $table->json('acknowledgement')->nullable()->after('letter');
            $table->timestamp('acknowledged_at')->nullable()->after('acknowledgement');
        });
    }

    public function down(): void
    {
        Schema::table('resignations', function (Blueprint $table) {
            $table->dropColumn(['acknowledgement', 'acknowledged_at']);
        });
    }
};
