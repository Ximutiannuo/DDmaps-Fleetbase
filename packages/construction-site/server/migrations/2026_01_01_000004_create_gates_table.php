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
        Schema::create('gates', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->string('_key', 191)->nullable()->index();
            $table->uuid('company_uuid')->nullable()->index();

            $table->string('name');
            $table->string('gate_type')->default('both')->comment('entrance | exit | both');
            $table->string('status')->default('open')->comment('open | closed');
            $table->json('location')->nullable()->comment('GeoJSON Point');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gates');
    }
};
