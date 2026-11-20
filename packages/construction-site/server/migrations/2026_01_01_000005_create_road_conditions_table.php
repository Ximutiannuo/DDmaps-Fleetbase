<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Road conditions are append-only time-series data. The model uses the
     * `DisablesSoftDeletes` trait for hard-delete semantics, but the base
     * Fleetbase Model applies the SoftDeletes global scope in some query
     * paths, so the `deleted_at` column must exist to keep those queries
     * working (see site_notifications migration for the same reason).
     */
    public function up(): void
    {
        Schema::create('road_conditions', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->string('_key', 191)->nullable()->index();
            $table->uuid('company_uuid')->nullable()->index();

            $table->foreignUuid('road_segment_uuid')->nullable()->index()->references('uuid')->on('road_segments')->onDelete('cascade');
            $table->string('status')->default('open')->comment('open | closed | partial | construction');
            $table->string('surface_condition')->default('dry')->comment('dry | wet | muddy | icy | pothole');
            $table->string('congestion')->default('none')->comment('none | light | heavy | standstill');
            $table->text('note')->nullable();
            $table->uuid('reported_by_uuid')->nullable()->index();
            $table->timestamp('reported_at')->nullable()->useCurrent();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('road_conditions');
    }
};
