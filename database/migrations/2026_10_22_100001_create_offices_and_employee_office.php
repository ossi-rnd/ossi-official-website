<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * OSSI's own offices (head office, branches), where office staff work. Kept
     * apart from clients on purpose: clients feed guard deployment, the roster
     * and the field side of the Organization chart, and an office must never
     * appear there. An office staffer's clock-in must be inside their office's
     * geofence, as a guard's is inside their post's.
     */
    public function up(): void
    {
        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->string('region', 20);
            $table->string('province', 100);
            $table->string('name', 150);
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('geofence_meters')->default(150);
            $table->timestamps();

            $table->unique(['region', 'province', 'name']);
        });

        Schema::table('employee_profiles', function (Blueprint $table) {
            // Office staff only (guards have a client). Deleting an office is refused while staff are assigned.
            $table->foreignId('office_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('office_id');
        });

        Schema::dropIfExists('offices');
    }
};
