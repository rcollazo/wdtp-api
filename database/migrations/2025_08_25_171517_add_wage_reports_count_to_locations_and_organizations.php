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
        // Add wage_reports_count to organizations table (if it doesn't exist)
        if (! Schema::hasColumn('organizations', 'wage_reports_count')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->integer('wage_reports_count')->default(0)->after('locations_count');
                $table->index('wage_reports_count');
            });
        }

        // Add wage_reports_count to locations table
        if (! Schema::hasColumn('locations', 'wage_reports_count')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->integer('wage_reports_count')->default(0)->after('is_verified');
                $table->index('wage_reports_count');
            });
        }

        // Initialize existing counts for organizations (approved wage reports only)
        DB::statement('
            UPDATE organizations 
            SET wage_reports_count = (
                SELECT COUNT(*) 
                FROM wage_reports 
                WHERE wage_reports.organization_id = organizations.id 
                AND wage_reports.status = ?
            )
        ', ['approved']);

        // Initialize existing counts for locations (approved wage reports only)
        DB::statement('
            UPDATE locations 
            SET wage_reports_count = (
                SELECT COUNT(*) 
                FROM wage_reports 
                WHERE wage_reports.location_id = locations.id 
                AND wage_reports.status = ?
            )
        ', ['approved']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('organizations', 'wage_reports_count')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropIndex(['wage_reports_count']);
                $table->dropColumn('wage_reports_count');
            });
        }

        if (Schema::hasColumn('locations', 'wage_reports_count')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropIndex(['wage_reports_count']);
                $table->dropColumn('wage_reports_count');
            });
        }
    }
};
