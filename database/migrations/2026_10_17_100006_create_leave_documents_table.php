<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The proof a leave needs (a medical certificate, a birth certificate, a
     * Solo Parent ID, a protection order): several files per request, since
     * paternity leave needs two. Sensitive: private disk, opened only by the
     * employee and whoever decides the leave, every view audited.
     */
    public function up(): void
    {
        Schema::create('leave_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('name')->nullable();
            $table->string('mime', 50);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_documents');
    }
};
