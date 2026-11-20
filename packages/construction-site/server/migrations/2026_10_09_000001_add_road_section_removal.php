<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('road_traffic_controls', function (Blueprint $table) {
            $table->boolean('is_removed')->default(false);
            $table->timestamp('removed_at')->nullable();
            $table->string('removal_origin_key', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('road_traffic_controls', fn (Blueprint $table) => $table->dropColumn(['is_removed', 'removed_at', 'removal_origin_key']));
    }
};
