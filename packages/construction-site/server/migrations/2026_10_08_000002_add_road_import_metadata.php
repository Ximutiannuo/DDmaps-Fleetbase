<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('road_segments', function (Blueprint $table) {
            $table->string('source', 20)->default('manual');
            $table->string('source_key', 100)->nullable();
            $table->json('source_meta')->nullable();
            $table->json('routing')->nullable();
            $table->unique(['company_uuid', 'source_key'], 'road_segments_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('road_segments', function (Blueprint $table) {
            $table->dropUnique('road_segments_source_unique');
            $table->dropColumn(['source', 'source_key', 'source_meta', 'routing']);
        });
    }
};
