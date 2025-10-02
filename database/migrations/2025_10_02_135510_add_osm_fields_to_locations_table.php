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
        Schema::table('locations', function (Blueprint $table) {
            // OpenStreetMap integration fields
            $table->bigInteger('osm_id')->nullable()->after('longitude');
            $table->enum('osm_type', ['node', 'way', 'relation'])->nullable()->after('osm_id');
            $table->jsonb('osm_data')->nullable()->after('osm_type');

            // Indexes for OSM queries
            $table->index('osm_id');
            $table->index(['osm_id', 'osm_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropIndex(['osm_id', 'osm_type']);
            $table->dropIndex(['osm_id']);
            $table->dropColumn(['osm_id', 'osm_type', 'osm_data']);
        });
    }
};
