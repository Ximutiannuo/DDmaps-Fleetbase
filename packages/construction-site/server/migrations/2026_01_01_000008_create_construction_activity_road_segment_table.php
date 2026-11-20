<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Pivot table linking construction activities to the road segments they affect.
     */
    public function up(): void
    {
        Schema::create('construction_activity_road_segment', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->nullable()->unique();
            $table->uuid('company_uuid')->nullable()->index();

            $table->uuid('construction_activity_uuid');
            $table->index('construction_activity_uuid', 'ca_activity_idx');
            $table->foreign('construction_activity_uuid', 'ca_activity_fk')->references('uuid')->on('construction_activities')->onDelete('cascade');
            $table->uuid('road_segment_uuid');
            $table->index('road_segment_uuid', 'ca_segment_idx');
            $table->foreign('road_segment_uuid', 'ca_segment_fk')->references('uuid')->on('road_segments')->onDelete('cascade');

            $table->timestamps();

            $table->unique(['construction_activity_uuid', 'road_segment_uuid'], 'construction_activity_road_segment_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('construction_activity_road_segment');
    }
};
