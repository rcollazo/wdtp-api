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
        // Only add organization_id if locations table exists and doesn't already have this column
        if (Schema::hasTable('locations') && ! Schema::hasColumn('locations', 'organization_id')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->foreignId('organization_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('organizations')
                    ->onDelete('cascade');

                // Add index for performance
                $table->index(['organization_id', 'created_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only drop column if locations table exists and has this column
        if (Schema::hasTable('locations') && Schema::hasColumn('locations', 'organization_id')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('organization_id');
            });
        }
    }
};
