<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('road_traffic_controls', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('public_id')->nullable()->unique();
            $table->string('_key')->nullable()->index();
            $table->uuid('company_uuid')->index();
            $table->uuid('service_area_uuid')->index();
            $table->string('target_key');
            $table->uuid('road_segment_uuid')->nullable();
            $table->json('geometry')->nullable();
            $table->string('status')->default('open');
            $table->string('congestion_mode')->default('auto');
            $table->string('manual_congestion')->default('none');
            $table->unsignedInteger('light_threshold')->default(3);
            $table->unsignedInteger('moderate_threshold')->default(6);
            $table->unsignedInteger('heavy_threshold')->default(10);
            $table->string('note', 1000)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_uuid', 'target_key']);
        });
        Schema::table('entry_registrations', function (Blueprint $table) {
            $table->decimal('gps_latitude', 10, 7)->nullable();
            $table->decimal('gps_longitude', 10, 7)->nullable();
            $table->float('gps_speed')->nullable();
            $table->float('gps_accuracy')->nullable();
            $table->timestamp('gps_reported_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('road_traffic_controls');
        Schema::table('entry_registrations', function (Blueprint $table) {
            $table->dropColumn(['gps_latitude', 'gps_longitude', 'gps_speed', 'gps_accuracy', 'gps_reported_at']);
        });
    }
};
