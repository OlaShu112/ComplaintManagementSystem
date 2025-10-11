<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('support_email')->nullable()->after('email');
            $table->string('support_phone')->nullable()->after('phone');
            $table->text('full_address')->nullable()->after('address');
            $table->string('city')->nullable()->after('full_address');
            $table->string('postal_code')->nullable()->after('city');
            $table->string('country')->default('United Kingdom')->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'support_email',
                'support_phone',
                'full_address',
                'city',
                'postal_code',
                'country'
            ]);
        });
    }
};
