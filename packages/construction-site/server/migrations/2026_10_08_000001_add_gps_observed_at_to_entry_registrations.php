<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('entry_registrations', function (Blueprint $table) {
            $table->timestamp('gps_observed_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('entry_registrations', function (Blueprint $table) {
            $table->dropColumn('gps_observed_at');
        });
    }
};
