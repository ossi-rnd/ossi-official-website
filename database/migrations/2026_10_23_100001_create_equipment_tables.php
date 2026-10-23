<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uniforms, radios and other gear given to guards and office staff:
     *  - equipment_items: what can be issued, with its price (Admin keeps the list);
     *  - equipment_issues: one item asked for, or given to one person, and its return;
     *  - equipment_incidents: a company item reported damaged or lost, the employee's
     *    explanation, and the decision (charged only after they were heard).
     */
    public function up(): void
    {
        Schema::create('equipment_items', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('kind', 20); // uniform: charged to the employee (with their authority to deduct); gear: company property
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->boolean('has_sizes')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('equipment_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('equipment_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('radio_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 160);
            $table->string('size', 10)->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->boolean('charged')->default(false);
            $table->foreignId('employee_deduction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->string('request_note', 500)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('acknowledgement')->nullable();
            $table->foreignId('returned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->string('return_note', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('status');
        });

        Schema::create('equipment_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_issue_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // damaged | lost
            $table->text('details');
            $table->foreignId('reported_by')->constrained('users');
            $table->date('explain_by');
            $table->text('explanation')->nullable();
            $table->timestamp('explained_at')->nullable();
            $table->string('decision', 20)->nullable(); // charged | not_charged
            $table->text('decision_reason')->nullable();
            $table->decimal('loss_amount', 10, 2)->nullable();
            $table->string('amount_basis', 300)->nullable();
            $table->decimal('installment', 10, 2)->nullable();
            $table->foreignId('employee_deduction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index('decision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_incidents');
        Schema::dropIfExists('equipment_issues');
        Schema::dropIfExists('equipment_items');
    }
};
