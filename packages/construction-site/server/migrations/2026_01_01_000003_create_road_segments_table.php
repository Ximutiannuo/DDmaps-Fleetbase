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
        Schema::create('road_segments', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->string('_key', 191)->nullable()->index();
            $table->uuid('company_uuid')->nullable()->index();

            $table->foreignUuid('site_road_uuid')->nullable()->index()->references('uuid')->on('site_roads')->onDelete('cascade');
            $table->string('name')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->string('status')->default('open')->comment('open | closed | partial | construction');
            $table->json('geometry')->nullable();
            // Forward reference to road_conditions; no DB-level FK to avoid a circular dependency.
            $table->uuid('current_condition_uuid')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('road_segments');
    }
};
