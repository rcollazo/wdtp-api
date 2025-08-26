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
        Schema::table('wage_reports', function (Blueprint $table) {
            $table->foreignId('position_category_id')
                ->nullable()
                ->after('location_id')
                ->constrained()
                ->nullOnDelete();
        });

        // Add composite index for duplicate detection
        Schema::table('wage_reports', function (Blueprint $table) {
            $table->index(['user_id', 'location_id', 'position_category_id'], 'wage_reports_duplicate_check_idx');
            $table->index(['position_category_id', 'status'], 'wage_reports_position_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wage_reports', function (Blueprint $table) {
            $table->dropIndex('wage_reports_duplicate_check_idx');
            $table->dropIndex('wage_reports_position_status_idx');
            $table->dropForeign(['position_category_id']);
            $table->dropColumn('position_category_id');
        });
    }
};
