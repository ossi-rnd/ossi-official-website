<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounting maintains the government rules in the app. Each change is a
     * new version with the date it takes effect; config/payroll.php stays the
     * built-in default until a version exists for a date.
     */
    public function up(): void
    {
        Schema::create('statutory_rule_sets', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->unique();
            // Same shape as payroll.schedules.*.statutory in config.
            $table->json('rules');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('minimum_wages', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->unique();
            $table->decimal('daily_amount', 10, 2);
            $table->string('wage_order', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minimum_wages');
        Schema::dropIfExists('statutory_rule_sets');
    }
};
