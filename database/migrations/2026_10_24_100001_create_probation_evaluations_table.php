<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The probation decision: the employee's head evaluates (pass, or doesn't meet the
     * standards made known when hired), HR approves or sends it back. An approved pass makes
     * them Regular; an approved fail ends employment on the probation's last day (applied_at).
     */
    public function up(): void
    {
        Schema::create('probation_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->string('outcome', 10); // pass | fail
            $table->text('reason');
            $table->date('ends_on'); // the probation's last day when evaluated
            $table->string('status', 10)->default('pending')->index(); // pending | approved | returned
            $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('evaluated_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('applied_at')->nullable(); // a fail: when employment was ended
            $table->timestamps();

            $table->index(['employee_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('probation_evaluations');
    }
};
