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
        Schema::create('industries', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->foreignId('parent_id')->nullable()->constrained('industries')->onDelete('set null');
            $table->smallInteger('depth')->default(0);
            $table->string('path', 512)->nullable();
            $table->smallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('visible_in_ui')->default(true);
            $table->timestamps();

            // Standard indexes
            $table->index('parent_id');
            $table->index('depth');
            $table->index('is_active');
            $table->index('visible_in_ui');
        });

        // Add custom constraints using DB statements
        DB::statement('CREATE UNIQUE INDEX industries_unique_sibling_name ON industries (COALESCE(parent_id, 0), lower(name))');
        DB::statement('ALTER TABLE industries ADD CONSTRAINT industries_parent_not_self CHECK (parent_id IS NULL OR parent_id <> id)');
        DB::statement('ALTER TABLE industries ADD CONSTRAINT check_slug_format CHECK (slug ~ \'^[a-z0-9]+(-[a-z0-9]+)*$\')');
        DB::statement('ALTER TABLE industries ADD CONSTRAINT check_depth CHECK (depth >= 0 AND depth <= 6)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop custom constraints and indexes first
        DB::statement('DROP INDEX IF EXISTS industries_unique_sibling_name');
        DB::statement('ALTER TABLE industries DROP CONSTRAINT IF EXISTS industries_parent_not_self');
        DB::statement('ALTER TABLE industries DROP CONSTRAINT IF EXISTS check_slug_format');
        DB::statement('ALTER TABLE industries DROP CONSTRAINT IF EXISTS check_depth');

        Schema::dropIfExists('industries');
    }
};
