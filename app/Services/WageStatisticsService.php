<?php

namespace App\Services;

use App\Models\WageReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class WageStatisticsService
{
    // Cache TTL: 15 minutes
    private const CACHE_TTL = 900;

    /**
     * Get global wage statistics for all approved wage reports
     */
    public function getGlobalStatistics(array $filters = []): array
    {
        $cacheKey = $this->buildCacheKey('global', null, $filters);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($filters) {
            $query = WageReport::query()->approved();
            $this->applyFilters($query, $filters);

            return $this->calculateStatistics($query, 'global', null, $filters);
        });
    }

    /**
     * Get wage statistics for a specific location
     */
    public function getLocationStatistics(int $locationId, array $filters = []): array
    {
        $cacheKey = $this->buildCacheKey('location', $locationId, $filters);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($locationId, $filters) {
            $query = WageReport::query()->approved()->forLocation($locationId);
            $this->applyFilters($query, $filters);

            return $this->calculateStatistics($query, 'location', $locationId, $filters);
        });
    }

    /**
     * Get wage statistics for a specific organization
     */
    public function getOrganizationStatistics(int $organizationId, array $filters = []): array
    {
        $cacheKey = $this->buildCacheKey('organization', $organizationId, $filters);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($organizationId, $filters) {
            $query = WageReport::query()->approved()->forOrganization($organizationId);
            $this->applyFilters($query, $filters);

            return $this->calculateStatistics($query, 'organization', $organizationId, $filters);
        });
    }

    /**
     * Calculate comprehensive statistics using PostgreSQL functions
     */
    private function calculateStatistics(Builder $query, string $context, ?int $contextId = null, array $filters = []): array
    {
        // Base statistical calculations using PostgreSQL
        $baseStats = $query->selectRaw('
            COUNT(*) as count,
            COALESCE(ROUND(AVG(normalized_hourly_cents)), 0) as average_cents,
            COALESCE(ROUND(percentile_cont(0.5) WITHIN GROUP (ORDER BY normalized_hourly_cents)), 0) as median_cents,
            COALESCE(MIN(normalized_hourly_cents), 0) as min_cents,
            COALESCE(MAX(normalized_hourly_cents), 0) as max_cents,
            COALESCE(ROUND(STDDEV(normalized_hourly_cents)), 0) as std_deviation_cents,
            COALESCE(ROUND(percentile_cont(0.25) WITHIN GROUP (ORDER BY normalized_hourly_cents)), 0) as p25,
            COALESCE(ROUND(percentile_cont(0.50) WITHIN GROUP (ORDER BY normalized_hourly_cents)), 0) as p50,
            COALESCE(ROUND(percentile_cont(0.75) WITHIN GROUP (ORDER BY normalized_hourly_cents)), 0) as p75,
            COALESCE(ROUND(percentile_cont(0.90) WITHIN GROUP (ORDER BY normalized_hourly_cents)), 0) as p90
        ')->first();

        // If no data found, return empty statistics
        if (! $baseStats || $baseStats->count == 0) {
            return $this->getEmptyStatistics();
        }

        $statistics = [
            'count' => (int) $baseStats->count,
            'average_cents' => (int) $baseStats->average_cents,
            'median_cents' => (int) $baseStats->median_cents,
            'min_cents' => (int) $baseStats->min_cents,
            'max_cents' => (int) $baseStats->max_cents,
            'std_deviation_cents' => (int) $baseStats->std_deviation_cents,
            'p25' => (int) $baseStats->p25,
            'p50' => (int) $baseStats->p50,
            'p75' => (int) $baseStats->p75,
            'p90' => (int) $baseStats->p90,
        ];

        // For breakdown queries, we need fresh query instances with same filters
        $freshQuery = WageReport::query()->approved();
        if ($context === 'location') {
            $freshQuery->forLocation($contextId);
        } elseif ($context === 'organization') {
            $freshQuery->forOrganization($contextId);
        }

        // Apply the same filters to breakdown queries
        $this->applyFilters($freshQuery, $filters ?? []);

        // Add employment type breakdown
        $statistics['employment_types'] = $this->getEmploymentTypeBreakdown(clone $freshQuery);

        // Add job title breakdown (top 10)
        $statistics['job_titles'] = $this->getJobTitleBreakdown(clone $freshQuery);

        // Add geographic distribution for global stats
        if ($context === 'global') {
            $statistics['geographic_distribution'] = $this->getGeographicDistribution(clone $freshQuery);
        }

        return $statistics;
    }

    /**
     * Get employment type breakdown with statistics
     */
    private function getEmploymentTypeBreakdown(Builder $query): array
    {
        return $query->selectRaw('
            employment_type as type,
            COUNT(*) as count,
            COALESCE(ROUND(AVG(normalized_hourly_cents)), 0) as average_cents
        ')
            ->groupBy('employment_type')
            ->orderByDesc('count')
            ->get()
            ->map(function ($item) {
                return [
                    'type' => $item->type,
                    'count' => (int) $item->count,
                    'average_cents' => (int) $item->average_cents,
                ];
            })
            ->toArray();
    }

    /**
     * Get top job titles breakdown with statistics
     */
    private function getJobTitleBreakdown(Builder $query): array
    {
        return $query->selectRaw('
            job_title as title,
            COUNT(*) as count,
            COALESCE(ROUND(AVG(normalized_hourly_cents)), 0) as average_cents
        ')
            ->groupBy('job_title')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'title' => $item->title,
                    'count' => (int) $item->count,
                    'average_cents' => (int) $item->average_cents,
                ];
            })
            ->toArray();
    }

    /**
     * Get geographic distribution (top 15 cities) for global stats
     */
    private function getGeographicDistribution(Builder $query): array
    {
        return $query->join('locations', 'wage_reports.location_id', '=', 'locations.id')
            ->selectRaw('
                locations.city,
                locations.state_province as state,
                COUNT(*) as count,
                COALESCE(ROUND(AVG(wage_reports.normalized_hourly_cents)), 0) as average_cents
            ')
            ->groupBy(['locations.city', 'locations.state_province'])
            ->orderByDesc('count')
            ->limit(15)
            ->get()
            ->map(function ($item) {
                return [
                    'city' => $item->city,
                    'state' => $item->state,
                    'count' => (int) $item->count,
                    'average_cents' => (int) $item->average_cents,
                ];
            })
            ->toArray();
    }

    /**
     * Return empty statistics structure
     */
    private function getEmptyStatistics(): array
    {
        return [
            'count' => 0,
            'average_cents' => 0,
            'median_cents' => 0,
            'min_cents' => 0,
            'max_cents' => 0,
            'std_deviation_cents' => 0,
            'p25' => 0,
            'p50' => 0,
            'p75' => 0,
            'p90' => 0,
            'employment_types' => [],
            'job_titles' => [],
            'geographic_distribution' => [],
        ];
    }

    /**
     * Clear statistics cache for a specific context
     */
    public function clearCache(?string $context = null, ?int $contextId = null): void
    {
        if ($context === null) {
            // Clear all statistics caches
            Cache::forget('wage_stats_global');

            // Note: In a real application, you'd want to clear location and organization
            // specific caches too, but that would require tracking all cached IDs
            // For now, we rely on the 15-minute TTL
            return;
        }

        match ($context) {
            'global' => Cache::forget('wage_stats_global'),
            'location' => Cache::forget("wage_stats_location_{$contextId}"),
            'organization' => Cache::forget("wage_stats_org_{$contextId}"),
            default => null,
        };
    }

    /**
     * Clear all caches when new wage reports are approved
     */
    public function clearAllCaches(): void
    {
        $this->clearCache();
    }

    /**
     * Apply additional filters to wage report query
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['date_from'])) {
            $query->where('effective_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('effective_date', '<=', $filters['date_to']);
        }

        if (isset($filters['employment_type'])) {
            $query->byEmploymentType($filters['employment_type']);
        }

        if (isset($filters['position_category_id'])) {
            $query->where('position_category_id', $filters['position_category_id']);
        }

        if (isset($filters['min_wage_cents'])) {
            $query->where('normalized_hourly_cents', '>=', $filters['min_wage_cents']);
        }

        if (isset($filters['max_wage_cents'])) {
            $query->where('normalized_hourly_cents', '<=', $filters['max_wage_cents']);
        }

        if (isset($filters['currency'])) {
            $query->inCurrency($filters['currency']);
        }

        if (isset($filters['unionized'])) {
            $query->where('unionized', $filters['unionized']);
        }

        if (isset($filters['tips_included'])) {
            $query->where('tips_included', $filters['tips_included']);
        }
    }

    /**
     * Build cache key with filters
     */
    private function buildCacheKey(string $context, ?int $contextId, array $filters): string
    {
        $baseKey = match ($context) {
            'global' => 'wage_stats_global',
            'location' => "wage_stats_location_{$contextId}",
            'organization' => "wage_stats_org_{$contextId}",
            default => 'wage_stats_unknown',
        };

        if (empty($filters)) {
            return $baseKey;
        }

        // Create deterministic hash of filters for cache key
        ksort($filters);
        $filtersHash = md5(serialize($filters));

        return "{$baseKey}_{$filtersHash}";
    }
}
