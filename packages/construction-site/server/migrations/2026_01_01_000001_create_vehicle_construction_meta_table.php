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
        Schema::create('vehicle_construction_meta', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->string('_key', 191)->nullable()->index();
            $table->uuid('company_uuid')->nullable()->index();

            // Link to the Fleetbase Fleetops vehicle (cross-package; no DB-level FK to allow isolated migration runs).
            $table->uuid('vehicle_uuid')->unique();

            // Construction-site specific classification and capabilities.
            $table->string('vehicle_category')->nullable()->comment('earthwork | concrete | material_transport | machinery | management');
            $table->json('specs')->nullable();
            $table->decimal('capacity', 10, 2)->nullable();
            $table->string('belonging_unit')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_construction_meta');
    }
};
