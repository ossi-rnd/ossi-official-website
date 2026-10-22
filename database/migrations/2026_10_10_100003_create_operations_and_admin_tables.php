<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The modules added with the operations roles:
     *
     *  - absence_notices: a guard says ahead of time they can't report for
     *    duty; Operations assigns a reliever to the post.
     *  - company_documents: Admin's company documents, licenses (with due
     *    dates) and client / legal documents. Renewing adds a new row and
     *    points the old one at it, so the history stays.
     *  - trainings + training_attendees: guards' training sessions.
     *  - complaints: filed by any employee, handled by HR.
     *  - company_policies: read by everyone.
     */
    public function up(): void
    {
        Schema::create('absence_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('duty_date');
            $table->text('reason');
            // Where they should have been, as it was when they filed.
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('detachment')->nullable();
            $table->string('post')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('reliever_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('covered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('covered_at')->nullable();
            $table->text('cover_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'duty_date']);
            $table->index(['reliever_id', 'duty_date']);
        });

        Schema::create('company_documents', function (Blueprint $table) {
            $table->id();
            $table->string('category', 20)->index();
            $table->string('title');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference_number', 100)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable()->index();
            $table->text('notes')->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->string('file_mime', 100);
            $table->unsignedInteger('file_size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            // Set when a renewal replaces this copy.
            $table->foreignId('replaced_by_id')->nullable()->constrained('company_documents')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('trainings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('provider')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('venue')->nullable();
            // How long the certificate counts, e.g. a yearly firearms refresher.
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('training_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('enrolled');
            $table->timestamps();

            $table->unique(['training_id', 'employee_profile_id']);
        });

        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30);
            $table->string('subject');
            $table->text('details');
            $table->string('against')->nullable();
            $table->date('incident_on')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('company_policies', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_policies');
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('training_attendees');
        Schema::dropIfExists('trainings');
        Schema::dropIfExists('company_documents');
        Schema::dropIfExists('absence_notices');
    }
};
