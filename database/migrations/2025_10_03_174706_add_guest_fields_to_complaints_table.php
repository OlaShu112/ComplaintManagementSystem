<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            // Make consumer_id nullable for guest submissions
            $table->foreignId('consumer_id')->nullable()->change();

            // Add guest information fields
            $table->string('guest_name')->nullable()->after('consumer_id');
            $table->string('guest_email')->nullable()->after('guest_name');
            $table->string('guest_phone')->nullable()->after('guest_email');
            $table->string('guest_organization')->nullable()->after('guest_phone');

            // Add tracking token for guest access
            $table->string('tracking_token')->nullable()->unique()->after('guest_organization');
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn(['guest_name', 'guest_email', 'guest_phone', 'guest_organization', 'tracking_token']);
            $table->foreignId('consumer_id')->nullable(false)->change();
        });
    }
};
