<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each employee's daily rate, set by HR, with the date it takes effect.
     * A raise is a NEW row, never an edit, so each payroll uses the rate in
     * force for its own period and the history stays readable.
     */
    public function up(): void
    {
        Schema::create('employee_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->decimal('daily_rate', 10, 2);
            $table->date('effective_from');
            $table->string('note')->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_profile_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_rates');
    }
};
