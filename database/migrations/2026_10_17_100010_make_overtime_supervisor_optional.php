<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Employees no longer pick a supervisor for overtime: a guard's goes to
     * Operations (Operation HQ and the region's Satellite Operation), office
     * staff's to the Executive, automatically. supervisor_id stays for older
     * requests, so it becomes optional.
     */
    public function up(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->foreignId('supervisor_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->foreignId('supervisor_id')->nullable(false)->change();
        });
    }
};
