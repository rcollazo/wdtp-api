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
        Schema::create('wage_reports', function (Blueprint $table) {
            $table->id();

            // Foreign key relationships
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();

            // Job details
            $table->string('job_title', 160);
            $table->enum('employment_type', ['full_time', 'part_time', 'seasonal', 'contract'])
                ->default('full_time');

            // Wage information
            $table->enum('wage_period', ['hourly', 'weekly', 'biweekly', 'monthly', 'yearly', 'per_shift']);
            $table->char('currency', 3)->default('USD');
            $table->integer('amount_cents')->unsigned();
            $table->integer('normalized_hourly_cents')->unsigned();

            // Additional wage context
            $table->smallInteger('hours_per_week')->nullable()->unsigned();
            $table->date('effective_date')->nullable();
            $table->boolean('tips_included')->default(false);
            $table->boolean('unionized')->nullable();

            // Source and moderation
            $table->enum('source', ['user', 'public_posting', 'employer_claim', 'other'])
                ->default('user');
            $table->enum('status', ['approved', 'pending', 'rejected'])
                ->default('approved');
            $table->smallInteger('sanity_score')->default(0);
            $table->text('notes')->nullable();

            $table->timestamps();

            // Indexes for common queries
            $table->index(['location_id', 'status']);
            $table->index(['organization_id', 'status']);
            $table->index(['user_id']);
            $table->index(['status']);
            $table->index(['effective_date']);
            $table->index(['normalized_hourly_cents']);
            $table->index(['job_title']);
            $table->index(['employment_type']);
            $table->index(['wage_period']);
            $table->index(['currency']);
        });

        // Add check constraints using raw SQL (PostgreSQL specific)
        DB::statement('ALTER TABLE wage_reports ADD CONSTRAINT wage_reports_amount_cents_positive CHECK (amount_cents > 0)');
        DB::statement('ALTER TABLE wage_reports ADD CONSTRAINT wage_reports_normalized_hourly_cents_positive CHECK (normalized_hourly_cents > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wage_reports');
    }
};
