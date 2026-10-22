<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail: no updated_at, and the model refuses updates and deletes.
     */
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name', 40)->index();
            $table->string('event', 60);
            $table->string('description');
            $table->nullableMorphs('subject');
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            // {"old": {...}, "new": {...}} or any context the event needs.
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
