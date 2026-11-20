<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('entry_registrations', function (Blueprint $table) {
            $table->uuid('destination_zone_uuid')->nullable()->index()->after('session_token');
            $table->boolean('is_navigating')->default(false)->index()->after('destination_zone_uuid');
            $table->json('active_route')->nullable()->after('is_navigating');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('entry_registrations', function (Blueprint $table) {
            $table->dropColumn(['destination_zone_uuid', 'is_navigating', 'active_route']);
        });
    }
};
