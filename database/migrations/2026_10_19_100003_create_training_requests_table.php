<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A guard asks for a training (a refresher, license renewal, first aid).
     * Admin approves it, usually by adding them to a session (training_id), or
     * declines with a reason. Roadmap item 29 (the training half).
     */
    public function up(): void
    {
        Schema::create('training_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->text('reason');
            $table->date('preferred_from')->nullable();
            $table->string('status', 20)->default('pending');
            // The session they were added to; null while approved but waiting for one.
            $table->foreignId('training_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_requests');
    }
};
