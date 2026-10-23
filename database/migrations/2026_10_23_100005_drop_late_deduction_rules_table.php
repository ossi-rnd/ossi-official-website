<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Late-deduction brackets are gone (the owner's choice, 2026-10-23): lateness is always the
     * exact minutes after grace at the hourly rate. No database had any bracket set.
     */
    public function up(): void
    {
        Schema::dropIfExists('late_deduction_rules');
    }

    public function down(): void
    {
        Schema::create('late_deduction_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('min_minutes');
            $table->unsignedInteger('max_minutes')->nullable();
            $table->decimal('deduction_amount', 10, 2);
            $table->timestamps();
        });
    }
};
