<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('entry_admissions', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('company_uuid')->index();
            $table->string('code_hash', 64)->unique();
            $table->string('plate_number', 32);
            $table->string('driver_phone', 16);
            $table->uuid('gate_uuid')->nullable();
            $table->uuid('issued_by_uuid');
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('entry_session_audits', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('company_uuid')->index();
            $table->uuid('entry_uuid')->index();
            $table->uuid('driver_uuid')->nullable();
            $table->uuid('vehicle_uuid')->nullable();
            $table->uuid('previous_vehicle_uuid')->nullable();
            $table->uuid('authorized_by_uuid')->nullable();
            $table->string('action', 32);
            $table->timestamp('created_at');
        });
        Schema::table('entry_registrations', function (Blueprint $table) {
            $table->timestamp('token_expires_at')->nullable()->index();
            $table->index(['company_uuid', 'status', 'exited_at', 'tracking_enabled'], 'entries_active_company_index');
        });
        // Preserve existing clients' raw tokens while removing raw secrets from the database.
        DB::table('entry_registrations')->update([
            'session_token' => DB::raw('SHA2(session_token, 256)'),
            'token_expires_at' => now()->addHours(12),
        ]);
        Schema::table('road_traffic_controls', fn (Blueprint $table) => $table->index(['company_uuid', 'road_segment_uuid'], 'traffic_company_segment_index'));
        Schema::table('site_notifications', fn (Blueprint $table) => $table->index(['company_uuid', 'read_at'], 'notifications_company_unread_index'));
    }

    public function down(): void
    {
        // Hashing is irreversible. Rollback revokes sessions; users register with fresh admission codes.
        DB::table('entry_registrations')->where('status', 'active')->update(['status' => 'exited', 'tracking_enabled' => false, 'exited_at' => now()]);
        Schema::table('site_notifications', fn (Blueprint $table) => $table->dropIndex('notifications_company_unread_index'));
        Schema::table('road_traffic_controls', fn (Blueprint $table) => $table->dropIndex('traffic_company_segment_index'));
        Schema::table('entry_registrations', function (Blueprint $table) {
            $table->dropIndex('entries_active_company_index');
            $table->dropColumn('token_expires_at');
        });
        Schema::dropIfExists('entry_admissions');
        Schema::dropIfExists('entry_session_audits');
    }
};
