<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proof for a complaint, optional (roadmap 31): photos, screenshots or a PDF,
     * when filing or later; who saw it, when there's nothing on paper; and HR
     * asking for proof from the case. Private files, opened only by the
     * complainant and the handler, every handler view audited.
     */
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->text('witnesses')->nullable()->after('against');
            $table->timestamp('proof_requested_at')->nullable()->after('acknowledged_at');
            $table->text('proof_request_note')->nullable()->after('proof_requested_at');
        });

        Schema::create('complaint_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('name')->nullable();
            $table->string('mime', 50);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_files');

        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn(['witnesses', 'proof_requested_at', 'proof_request_note']);
        });
    }
};
