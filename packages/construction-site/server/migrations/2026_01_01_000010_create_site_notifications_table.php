<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Site notifications are an append-only log. The model uses the
     * `DisablesSoftDeletes` trait for hard-delete semantics, but the base
     * Fleetbase Model applies the SoftDeletes global scope in some query
     * paths, so the `deleted_at` column must exist to keep those queries
     * working (without it, list queries 500 with "Unknown column
     * 'site_notifications.deleted_at'").
     */
    public function up(): void
    {
        Schema::create('site_notifications', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->string('_key', 191)->nullable()->index();
            $table->uuid('company_uuid')->nullable()->index();

            $table->string('title');
            $table->text('message');
            $table->string('type')->default('system')->comment('road_status | event | construction | entry | system');
            $table->string('severity')->default('info')->comment('info | warning | critical');
            $table->string('source_type')->nullable();
            $table->uuid('source_uuid')->nullable();
            $table->index(['source_type', 'source_uuid']);
            $table->string('target_scope')->default('all');
            $table->timestamp('read_at')->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_notifications');
    }
};
