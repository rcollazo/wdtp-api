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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();

            // Core identification
            $table->string('name', 160)->index();
            $table->string('slug', 160)->unique();
            $table->string('legal_name', 200)->nullable();
            $table->string('website_url', 200)->nullable();
            $table->string('domain', 120)->nullable()->index();
            $table->text('description')->nullable();
            $table->string('logo_url', 300)->nullable();

            // Industry relationship
            $table->foreignId('primary_industry_id')
                ->nullable()
                ->constrained('industries')
                ->onDelete('set null');

            // Status management
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->enum('verification_status', ['unverified', 'pending', 'verified', 'rejected'])
                ->default('unverified');

            // User relationships
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');
            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');
            $table->timestamp('verified_at')->nullable();

            // Counters for performance
            $table->unsignedInteger('locations_count')->default(0);
            $table->unsignedInteger('wage_reports_count')->default(0);

            // Display flags
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('visible_in_ui')->default(true)->index();

            $table->timestamps();

            // Additional indexes for performance
            $table->index(['status', 'is_active']);
            $table->index(['verification_status', 'created_at']);
        });

        // Create case-insensitive unique constraint on domain
        DB::statement('CREATE UNIQUE INDEX organizations_domain_lower_unique ON organizations (lower(domain)) WHERE domain IS NOT NULL');

        // Add slug format constraint (alphanumeric, hyphens, underscores)
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT check_slug_format CHECK (slug ~ '^[a-z0-9][a-z0-9_-]*[a-z0-9]$' OR slug ~ '^[a-z0-9]$')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
