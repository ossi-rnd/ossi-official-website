<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clients by location: Region → Province → Client (e.g. Region IV-A →
     * Cavite → Metrobank). A guard's detachment is a client; their own home
     * address stays on the profile and has nothing to do with this.
     *
     * employee_profiles.detachment keeps the client's name as plain text, so
     * every existing screen and report that shows a detachment keeps working.
     * SaveClient updates it when a client is renamed.
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('region', 20)->index();
            $table->string('province', 100);
            $table->string('name', 150);
            $table->string('site_address')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_number', 50)->nullable();
            $table->timestamps();

            $table->unique(['region', 'province', 'name']);
        });

        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('shift_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });

        Schema::dropIfExists('clients');
    }
};
