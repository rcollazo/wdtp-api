<?php

namespace App\Observers;

use App\Models\Location;
use App\Models\Organization;
use App\Models\WageReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WageReportObserver
{
    // MAD (Median Absolute Deviation) threshold for outlier detection
    private const K_MAD = 6;

    // Minimum sample size for reliable statistics
    private const MIN_SAMPLE_SIZE = 3;

    /**
     * Handle the WageReport "creating" event.
     * Set sanity score and status before saving.
     */
    public function creating(WageReport $wageReport): void
    {
        // Calculate sanity score and set appropriate status
        $sanityScore = $this->calculateSanityScore($wageReport);
        $wageReport->sanity_score = $sanityScore;

        // Set status based on sanity score
        $wageReport->status = $sanityScore >= 0 ? 'approved' : 'pending';
    }

    /**
     * Handle the WageReport "created" event.
     * Update counters, award XP, and bump cache versions.
     */
    public function created(WageReport $wageReport): void
    {
        DB::transaction(function () use ($wageReport) {
            // Only count approved reports for counters
            if ($wageReport->status === 'approved') {
                $this->incrementCounters($wageReport);
            }

            // Award XP for submission
            $this->awardExperiencePoints($wageReport);

            // Bump cache versions
            $this->bumpCacheVersions();
        });
    }

    /**
     * Handle the WageReport "updated" event.
     * Handle status changes and update counters accordingly.
     */
    public function updated(WageReport $wageReport): void
    {
        // Check if status changed from/to approved
        if ($wageReport->wasChanged('status')) {
            $originalStatus = $wageReport->getOriginal('status');
            $newStatus = $wageReport->status;

            DB::transaction(function () use ($wageReport, $originalStatus, $newStatus) {
                // Handle counter updates based on status change
                if ($originalStatus === 'approved' && $newStatus !== 'approved') {
                    // Was approved, now not approved - decrement counters
                    $this->decrementCounters($wageReport);
                } elseif ($originalStatus !== 'approved' && $newStatus === 'approved') {
                    // Wasn't approved, now approved - increment counters
                    $this->incrementCounters($wageReport);
                }

                // Bump cache versions
                $this->bumpCacheVersions();
            });
        }
    }

    /**
     * Handle the WageReport "deleted" event.
     * Decrement counters and bump cache versions.
     */
    public function deleted(WageReport $wageReport): void
    {
        DB::transaction(function () use ($wageReport) {
            // Only decrement counters if it was an approved report
            if ($wageReport->status === 'approved') {
                $this->decrementCounters($wageReport);
            }

            // Bump cache versions
            $this->bumpCacheVersions();
        });
    }

    /**
     * Handle the WageReport "restored" event.
     * Restore counters if report was approved.
     */
    public function restored(WageReport $wageReport): void
    {
        DB::transaction(function () use ($wageReport) {
            // Only increment counters if it's an approved report
            if ($wageReport->status === 'approved') {
                $this->incrementCounters($wageReport);
            }

            // Bump cache versions
            $this->bumpCacheVersions();
        });
    }

    /**
     * Handle the WageReport "force deleted" event.
     * Same as regular deletion - decrement counters.
     */
    public function forceDeleted(WageReport $wageReport): void
    {
        DB::transaction(function () use ($wageReport) {
            // Only decrement counters if it was an approved report
            if ($wageReport->status === 'approved') {
                $this->decrementCounters($wageReport);
            }

            // Bump cache versions
            $this->bumpCacheVersions();
        });
    }

    /**
     * Calculate sanity score using MAD (Median Absolute Deviation) algorithm
     */
    private function calculateSanityScore(WageReport $wageReport): int
    {
        try {
            $normalizedWage = $wageReport->normalized_hourly_cents;

            // Try location-level statistics first
            if ($wageReport->location_id) {
                $locationStats = $this->getLocationWageStatistics($wageReport->location_id);
                if ($locationStats && $locationStats['count'] >= self::MIN_SAMPLE_SIZE) {
                    return $this->calculateMADScore($normalizedWage, $locationStats);
                }
            }

            // Fall back to organization-level statistics
            if ($wageReport->organization_id) {
                $orgStats = $this->getOrganizationWageStatistics($wageReport->organization_id);
                if ($orgStats && $orgStats['count'] >= self::MIN_SAMPLE_SIZE) {
                    return $this->calculateMADScore($normalizedWage, $orgStats);
                }
            }

            // Fall back to global bounds check
            return $this->calculateGlobalBoundsScore($normalizedWage);

        } catch (\Exception $e) {
            Log::warning('Error calculating sanity score', [
                'wage_report_id' => $wageReport->id ?? 'new',
                'error' => $e->getMessage(),
            ]);

            return 0; // Neutral score on error
        }
    }

    /**
     * Get wage statistics for a specific location
     */
    private function getLocationWageStatistics(int $locationId): ?array
    {
        $stats = DB::select("
            SELECT 
                COUNT(*) as count,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY normalized_hourly_cents) as median,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY ABS(normalized_hourly_cents - 
                    (SELECT PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY normalized_hourly_cents) 
                     FROM wage_reports 
                     WHERE location_id = ? AND status = 'approved')
                )) as mad
            FROM wage_reports 
            WHERE location_id = ? AND status = 'approved'
        ", [$locationId, $locationId]);

        $result = $stats[0] ?? null;

        return $result ? [
            'count' => (int) $result->count,
            'median' => (float) $result->median,
            'mad' => (float) $result->mad,
        ] : null;
    }

    /**
     * Get wage statistics for a specific organization
     */
    private function getOrganizationWageStatistics(int $organizationId): ?array
    {
        $stats = DB::select("
            SELECT 
                COUNT(*) as count,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY normalized_hourly_cents) as median,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY ABS(normalized_hourly_cents - 
                    (SELECT PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY normalized_hourly_cents) 
                     FROM wage_reports 
                     WHERE organization_id = ? AND status = 'approved')
                )) as mad
            FROM wage_reports 
            WHERE organization_id = ? AND status = 'approved'
        ", [$organizationId, $organizationId]);

        $result = $stats[0] ?? null;

        return $result ? [
            'count' => (int) $result->count,
            'median' => (float) $result->median,
            'mad' => (float) $result->mad,
        ] : null;
    }

    /**
     * Calculate MAD-based score
     */
    private function calculateMADScore(int $wage, array $stats): int
    {
        $median = $stats['median'];
        $mad = $stats['mad'];

        // Avoid division by zero
        if ($mad == 0) {
            return $wage == $median ? 5 : 0;
        }

        $deviation = abs($wage - $median);
        $madScore = $deviation / $mad;

        // Convert to integer score: -5 to 5 scale
        if ($madScore > self::K_MAD) {
            return -5; // Strong outlier
        } elseif ($madScore > 3) {
            return -2; // Moderate outlier
        } elseif ($madScore > 1.5) {
            return 0;  // Slight concern
        } else {
            return 5;  // Normal range
        }
    }

    /**
     * Calculate score based on global wage bounds
     */
    private function calculateGlobalBoundsScore(int $wage): int
    {
        // Check against global bounds
        if ($wage < WageReport::MIN_HOURLY_CENTS) {
            return -5; // Below minimum wage
        } elseif ($wage > WageReport::MAX_HOURLY_CENTS) {
            return -5; // Above maximum reasonable wage
        } else {
            return 0; // Within reasonable bounds
        }
    }

    /**
     * Increment counters for location and organization
     */
    private function incrementCounters(WageReport $wageReport): void
    {
        if ($wageReport->location_id) {
            Location::where('id', $wageReport->location_id)
                ->increment('wage_reports_count');
        }

        if ($wageReport->organization_id) {
            Organization::where('id', $wageReport->organization_id)
                ->increment('wage_reports_count');
        }
    }

    /**
     * Decrement counters for location and organization
     */
    private function decrementCounters(WageReport $wageReport): void
    {
        if ($wageReport->location_id) {
            Location::where('id', $wageReport->location_id)
                ->where('wage_reports_count', '>', 0)
                ->decrement('wage_reports_count');
        }

        if ($wageReport->organization_id) {
            Organization::where('id', $wageReport->organization_id)
                ->where('wage_reports_count', '>', 0)
                ->decrement('wage_reports_count');
        }
    }

    /**
     * Award experience points to user for wage report submission
     */
    private function awardExperiencePoints(WageReport $wageReport): void
    {
        if (! $wageReport->user_id) {
            return; // Anonymous submissions don't get XP
        }

        $user = $wageReport->user;
        if (! $user) {
            return;
        }

        try {
            // Award base XP for submission (only for approved reports)
            if ($wageReport->status === 'approved') {
                $user->addPoints(10, null, null, 'wage_report_submitted');

                // Bonus XP for first report
                if ($user->wageReports()->count() === 1) {
                    $user->addPoints(25, null, null, 'first_wage_report');
                }
            }
        } catch (\Exception $e) {
            Log::warning('Error awarding experience points', [
                'user_id' => $user->id,
                'wage_report_id' => $wageReport->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Bump cache versions for invalidation
     */
    private function bumpCacheVersions(): void
    {
        Cache::increment('wages:ver');
        Cache::increment('orgs:ver');
        Cache::increment('locations:ver');
    }
}
