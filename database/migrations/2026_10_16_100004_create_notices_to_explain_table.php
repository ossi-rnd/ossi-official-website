<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notice to Explain (roadmap item 30): procedural due process before a real
     * sanction, the twin-notice rule (Labor Code Art. 297–299, King of Kings
     * Transport v. Mamac, 2007):
     *
     *   1st notice   the specific acts, the rule broken, the possible penalty, and
     *                at least 5 calendar days to explain in writing (explain_by)
     *   explanation  the employee's side (and whether they want a hearing)
     *   hearing      optional, when asked for or needed
     *   2nd notice   the decision and its reasons
     *
     * The employee acknowledges receiving each notice (not agreeing with it).
     */
    public function up(): void
    {
        Schema::create('notices_to_explain', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 150);
            $table->date('incident_date');
            $table->text('details');
            $table->text('rule_violated');
            $table->string('possible_penalty', 20);
            $table->date('explain_by');
            $table->string('status', 20)->default('issued')->index(); // issued | explained | decided
            $table->timestamp('acknowledged_at')->nullable();

            $table->text('explanation')->nullable();
            $table->boolean('wants_hearing')->default(false);
            $table->timestamp('explained_at')->nullable();

            $table->timestamp('hearing_at')->nullable();
            $table->string('hearing_place')->nullable();

            $table->string('decision', 20)->nullable();
            $table->text('decision_reason')->nullable();
            $table->unsignedSmallInteger('suspension_days')->nullable();
            $table->date('suspension_from')->nullable();
            $table->foreignId('merit_record_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('decision_acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices_to_explain');
    }
};
