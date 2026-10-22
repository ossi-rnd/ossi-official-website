<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            // The date the shift STARTED on, so a 22:00-06:00 shift stays one log.
            $table->date('log_date');
            $table->dateTime('clock_in_at')->nullable();
            $table->dateTime('clock_out_at')->nullable();
            $table->string('status', 30)->default('incomplete');
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('undertime_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->timestamps();

            // One log per person per day. The database enforces it, not just the app.
            $table->unique(['user_id', 'log_date']);
            $table->index('log_date');
        });

        Schema::create('time_log_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('time_log_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->dateTime('punched_at')->index();
            $table->string('photo_path');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('accuracy_meters')->nullable();
            $table->string('address', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_log_punches');
        Schema::dropIfExists('time_logs');
    }
};
