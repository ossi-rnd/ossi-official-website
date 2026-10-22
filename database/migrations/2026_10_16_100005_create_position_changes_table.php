<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Position history (roadmap item 22, promotion): every change of an employee's
     * position, with its date. Pay looks up the position held on each cut-off's
     * start date (EffectiveRatesQuery), so a promotion raises pay from its date
     * only, and earlier payslips (and their recomputation) keep the old rate.
     *
     *  - kind          promotion | transfer (a lateral move) | edit (changed on the employee form)
     *  - effective_on  for promotions and transfers: a cut-off start (the 1st or the 16th)
     *  - applied_at    when employee_profiles.position took it (a future one: on its day)
     */
    public function up(): void
    {
        Schema::create('position_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->string('from_position', 40)->nullable();
            $table->string('to_position', 40);
            $table->string('kind', 20);
            $table->date('effective_on');
            $table->text('reason')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['employee_profile_id', 'effective_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_changes');
    }
};
