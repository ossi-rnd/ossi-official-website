<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Generate all" runs in the background (GenerateRunPayslipsJob), so a run with thousands of
     * employees doesn't hang one request. Its progress lives on the run, shown as a bar:
     * status (queued, running, done, failed), how many of how many, and who started it.
     */
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->string('generation_status', 10)->nullable();
            $table->unsignedInteger('generation_total')->default(0);
            $table->unsignedInteger('generation_done')->default(0);
            $table->unsignedInteger('generation_generated')->default(0);
            $table->unsignedInteger('generation_skipped_no_rate')->default(0);
            $table->foreignId('generation_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generation_started_at')->nullable();
            $table->timestamp('generation_finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('generation_by');
            $table->dropColumn(['generation_status', 'generation_total', 'generation_done', 'generation_generated', 'generation_skipped_no_rate', 'generation_started_at', 'generation_finished_at']);
        });
    }
};
