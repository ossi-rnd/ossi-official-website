<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One phone per guard (the company gives them one): the phone that clocks
     * in is remembered by a random code in a cookie, stored here only as a hash.
     * A punch from another phone is saved and FLAGGED, never refused.
     */
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64);
            // "Android · Chrome": from the browser, for people to recognise it.
            $table->string('label', 100)->nullable();
            $table->timestamp('registered_at');
            // Null: registered by their first clock-in. Else who set it (a replacement phone).
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('time_log_punches', function (Blueprint $table) {
            $table->char('device_hash', 64)->nullable()->after('address');
            $table->string('device_label', 100)->nullable()->after('device_hash');
            $table->boolean('other_device')->default(false)->after('device_label');
        });
    }

    public function down(): void
    {
        Schema::table('time_log_punches', function (Blueprint $table) {
            $table->dropColumn(['device_hash', 'device_label', 'other_device']);
        });

        Schema::dropIfExists('user_devices');
    }
};
