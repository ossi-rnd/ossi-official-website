<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resignations, from the letter to the last day and clearance (Labor Code
     * Art. 300: 30 days' written notice, or immediate for a just cause).
     *
     *  - requested_last_day: what the employee asked for; last_day: what HR accepted
     *  - notice_waived: a last day under 30 days' notice, agreed by HR
     *  - for_executive: filed by HR staff, so the Executive handles it (never HR)
     *  - clearance: {item: {at, by}} for each ClearanceItem ticked
     *  - separated_at: the day after the last day, when the status became Resigned
     *  - recorded_by: HR, when they entered a paper letter for the employee
     */
    public function up(): void
    {
        Schema::create('resignations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('for_executive')->default(false);
            $table->string('reason', 30);
            $table->string('reason_other')->nullable();
            $table->text('letter');
            $table->date('requested_last_day');
            $table->boolean('asks_waiver')->default(false);
            $table->string('status', 20)->default('pending')->index();
            $table->date('last_day')->nullable();
            $table->boolean('notice_waived')->default(false);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->json('clearance')->nullable();
            $table->timestamp('separated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resignations');
    }
};
