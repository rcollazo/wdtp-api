<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ensure PostGIS extension exists
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

        Schema::create('locations', function (Blueprint $table) {
            $table->id();

            // Organization relationship
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            // Basic information
            $table->string('name', 255);
            $table->string('slug', 255)->unique();

            // Address fields
            $table->string('address_line_1', 255);
            $table->string('address_line_2', 255)->nullable();
            $table->string('city', 100);
            $table->string('state_province', 100);
            $table->string('postal_code', 20);
            $table->string('country_code', 2)->default('US');

            // Contact information
            $table->string('phone', 20)->nullable();
            $table->string('website_url', 500)->nullable();
            $table->text('description')->nullable();

            // Cached coordinates for quick access and sorting
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);

            // Status and metadata
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->text('verification_notes')->nullable();

            $table->timestamps();

            // Standard indexes
            $table->index(['organization_id', 'is_active']);
            $table->index(['latitude', 'longitude']);
        });

        // Add PostGIS geography column and spatial index
        // Using geography(Point,4326) for accurate distance calculations
        DB::statement('ALTER TABLE locations ADD COLUMN point GEOGRAPHY(POINT, 4326)');

        // Create GiST index for spatial queries
        DB::statement('CREATE INDEX locations_point_gist_idx ON locations USING GIST (point)');

        // Create full-text search index for location name, address, and city
        DB::statement('CREATE INDEX locations_name_address_city_fulltext 
            ON locations USING gin(to_tsvector(\'english\', 
            coalesce(name, \'\') || \' \' || 
            coalesce(address_line_1, \'\') || \' \' || 
            coalesce(city, \'\')))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
