<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Time away is measured against when the person said they'd be back, not
     * a fixed limit: traffic, a bank queue or a long errand is normal work.
     *  - away_logs.expected_back_at: chosen when stepping out (empty on older
     *    rows: the reason's usual time applies);
     *  - away_extensions: each "I need more time", with how much and why.
     */
    public function up(): void
    {
        Schema::table('away_logs', function (Blueprint $table) {
            $table->timestamp('expected_back_at')->nullable()->after('left_longitude');
        });

        Schema::create('away_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('away_log_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('minutes');
            $table->string('reason', 200);
            $table->timestamp('previous_back_at');
            $table->timestamp('new_back_at');
            $table->timestamps();

            $table->index(['away_log_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('away_extensions');

        Schema::table('away_logs', function (Blueprint $table) {
            $table->dropColumn('expected_back_at');
        });
    }
};
