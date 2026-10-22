<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The company's daily rate for each position, from a date. An employee
     * without a personal rate is paid their position's rate.
     */
    public function up(): void
    {
        Schema::create('position_rates', function (Blueprint $table) {
            $table->id();
            $table->string('position', 30);
            $table->decimal('daily_rate', 10, 2);
            $table->date('effective_from');
            $table->string('note')->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['position', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_rates');
    }
};
