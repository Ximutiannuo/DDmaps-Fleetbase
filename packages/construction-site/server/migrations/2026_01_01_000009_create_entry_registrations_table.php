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
        Schema::create('entry_registrations', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->string('_key', 191)->nullable()->index();
            $table->uuid('company_uuid')->nullable()->index();

            // Cross-package references to Fleetops Vehicle/Driver (no DB-level FK to allow isolated migration runs).
            $table->uuid('vehicle_uuid')->nullable()->index();
            $table->uuid('driver_uuid')->nullable()->index();
            // Intra-package gate reference with set-null on delete.
            $table->foreignUuid('gate_uuid')->nullable()->index()->references('uuid')->on('gates')->onDelete('set null');

            $table->string('driver_name')->nullable();
            $table->string('driver_phone')->nullable();
            $table->string('purpose')->default('other');
            $table->string('status')->default('active')->comment('active | exited');
            $table->timestamp('entered_at')->nullable()->useCurrent();
            $table->timestamp('exited_at')->nullable();
            $table->boolean('tracking_enabled')->default(true);
            $table->string('session_token', 64)->unique();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entry_registrations');
    }
};
