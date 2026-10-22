<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The NTC radio masterlist: every two-way radio the agency owns, its NTC permit
     * (and when it expires), and where it is: at a client post, with a guard, or in stock.
     */
    public function up(): void
    {
        Schema::create('radios', function (Blueprint $table) {
            $table->id();
            $table->string('brand', 80);
            $table->string('model', 80);
            $table->string('serial_number', 80)->unique();
            $table->string('frequency', 80)->nullable();
            $table->string('ntc_permit_number', 80)->nullable();
            $table->date('permit_expires_on')->nullable();
            $table->string('status', 20)->default('spare');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index('permit_expires_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radios');
    }
};
