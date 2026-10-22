<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Laravel's standard database-notification table: the bell in the top bar
     * reads it. One row per person notified.
     *
     * sent_alerts makes scheduled checks idempotent: a row per (alert, subject)
     * that was already sent, so rerunning the scheduler never notifies twice.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sent_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('alert', 60);
            $table->morphs('subject');
            $table->timestamp('sent_at');

            $table->unique(['alert', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_alerts');
        Schema::dropIfExists('notifications');
    }
};
