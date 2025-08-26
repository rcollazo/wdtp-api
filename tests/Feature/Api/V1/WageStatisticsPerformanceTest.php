<?php

namespace Tests\Feature\Api\V1;

use App\Models\Industry;
use App\Models\Location;
use App\Models\Organization;
use App\Models\PositionCategory;
use App\Models\User;
use App\Models\WageReport;
use App\Services\WageStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WageStatisticsPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private WageStatisticsService $service;

    private Organization $organization;

    private array $locations = [];

    private array $positionCategories = [];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new WageStatisticsService;

        // Create base test data
        $industry = Industry::factory()->create(['name' => 'Food Service']);

        $this->organization = Organization::factory()->active()->verified()->create([
            'name' => 'Test Restaurant Chain',
            'primary_industry_id' => $industry->id,
        ]);

        // Create multiple locations
        for ($i = 1; $i <= 5; $i++) {
            $this->locations[] = Location::factory()->active()->verified()->create([
                'organization_id' => $this->organization->id,
                'name' => "Location {$i}",
                'city' => "City {$i}",
                'state_province' => 'NY',
            ]);
        }

        // Create multiple position categories
        for ($i = 1; $i <= 3; $i++) {
            $this->positionCategories[] = PositionCategory::factory()->active()->create([
                'industry_id' => $industry->id,
                'name' => "Position {$i}",
            ]);
        }

        $this->user = User::factory()->create();
    }

    /** @test */
    public function global_statistics_perform_within_acceptable_limits_with_large_dataset(): void
    {
        // Create a substantial dataset (1000 wage reports)
        $this->createLargeWageReportDataset(1000);

        // Clear cache to force fresh calculation
        Cache::flush();

        // Measure performance
        $startTime = microtime(true);
        $response = $this->getJson('/api/v1/wage-reports/stats');
        $endTime = microtime(true);

        $executionTime = $endTime - $startTime;

        $response->assertStatus(200);

        // Performance requirement: < 2 seconds for complex statistics
        $this->assertLessThan(2.0, $executionTime, "Statistics endpoint took {$executionTime}s, should be under 2.0s");

        $data = $response->json('data');

        // Verify correctness
        $this->assertEquals(1000, $data['count']);
        $this->assertGreaterThan(0, $data['average_cents']);
        $this->assertGreaterThan(0, $data['median_cents']);
        $this->assertNotEmpty($data['employment_types']);
        $this->assertNotEmpty($data['job_titles']);
        $this->assertNotEmpty($data['geographic_distribution']);
    }

    /** @test */
    public function location_statistics_perform_well_with_focused_dataset(): void
    {
        $location = $this->locations[0];

        // Create 200 wage reports for this location
        WageReport::factory()->approved()->count(200)->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $location->id,
            'position_category_id' => $this->positionCategories[0]->id,
            'normalized_hourly_cents' => fake()->numberBetween(1500, 3000),
        ]);

        // Clear cache
        Cache::flush();

        // Measure performance
        $startTime = microtime(true);
        $response = $this->getJson("/api/v1/locations/{$location->id}/wage-stats");
        $endTime = microtime(true);

        $executionTime = $endTime - $startTime;

        $response->assertStatus(200);

        // Performance requirement: < 1 second for location-specific stats
        $this->assertLessThan(1.0, $executionTime, "Location statistics took {$executionTime}s, should be under 1.0s");

        $data = $response->json('data');
        $this->assertEquals(200, $data['count']);
    }

    /** @test */
    public function organization_statistics_handle_multiple_locations_efficiently(): void
    {
        // Create wage reports across all locations for this organization
        foreach ($this->locations as $location) {
            WageReport::factory()->approved()->count(50)->create([
                'user_id' => $this->user->id,
                'organization_id' => $this->organization->id,
                'location_id' => $location->id,
                'position_category_id' => fake()->randomElement($this->positionCategories)->id,
                'normalized_hourly_cents' => fake()->numberBetween(1500, 3000),
            ]);
        }

        // Clear cache
        Cache::flush();

        // Measure performance
        $startTime = microtime(true);
        $response = $this->getJson("/api/v1/organizations/{$this->organization->id}/wage-stats");
        $endTime = microtime(true);

        $executionTime = $endTime - $startTime;

        $response->assertStatus(200);

        // Performance requirement: < 1.5 seconds for organization-wide stats
        $this->assertLessThan(1.5, $executionTime, "Organization statistics took {$executionTime}s, should be under 1.5s");

        $data = $response->json('data');
        $this->assertEquals(250, $data['count']); // 50 * 5 locations
    }

    /** @test */
    public function caching_significantly_improves_performance(): void
    {
        // Create moderate dataset
        $this->createLargeWageReportDataset(500);

        // Clear cache and measure first request (uncached)
        Cache::flush();
        $startTime = microtime(true);
        $response1 = $this->getJson('/api/v1/wage-reports/stats');
        $endTime = microtime(true);
        $uncachedTime = $endTime - $startTime;

        $response1->assertStatus(200);

        // Measure second request (cached)
        $startTime = microtime(true);
        $response2 = $this->getJson('/api/v1/wage-reports/stats');
        $endTime = microtime(true);
        $cachedTime = $endTime - $startTime;

        $response2->assertStatus(200);

        // Cached request should be at least 5x faster
        $this->assertLessThan($uncachedTime / 5, $cachedTime,
            "Cached request ({$cachedTime}s) should be significantly faster than uncached ({$uncachedTime}s)");

        // Verify data consistency
        $this->assertEquals($response1->json('data'), $response2->json('data'));
    }

    /** @test */
    public function filtered_statistics_perform_adequately(): void
    {
        // Create diverse dataset for filtering
        $this->createDiverseWageReportDataset(800);

        // Clear cache
        Cache::flush();

        // Test filtered request with multiple filters
        $filters = [
            'employment_type' => 'full_time',
            'min_wage' => '15.00',
            'max_wage' => '30.00',
            'date_from' => '2024-01-01',
            'date_to' => '2024-12-31',
        ];

        $startTime = microtime(true);
        $response = $this->getJson('/api/v1/wage-reports/stats?'.http_build_query($filters));
        $endTime = microtime(true);

        $executionTime = $endTime - $startTime;

        $response->assertStatus(200);

        // Performance requirement: < 2.5 seconds for complex filtered statistics
        $this->assertLessThan(2.5, $executionTime, "Filtered statistics took {$executionTime}s, should be under 2.5s");

        $data = $response->json('data');
        $this->assertGreaterThan(0, $data['count']);
        $this->assertGreaterThanOrEqual(1500, $data['min_cents']); // >= $15.00
        $this->assertLessThanOrEqual(3000, $data['max_cents']); // <= $30.00
    }

    /** @test */
    public function postgresql_percentile_calculations_are_efficient(): void
    {
        // Create dataset with known distribution
        $wages = range(1000, 5000, 50); // $10.00 to $50.00 in $0.50 increments

        foreach ($wages as $wage) {
            WageReport::factory()->approved()->create([
                'user_id' => $this->user->id,
                'organization_id' => $this->organization->id,
                'location_id' => fake()->randomElement($this->locations)->id,
                'position_category_id' => fake()->randomElement($this->positionCategories)->id,
                'normalized_hourly_cents' => $wage,
            ]);
        }

        // Clear cache
        Cache::flush();

        // Test percentile calculation performance
        $startTime = microtime(true);
        $statistics = $this->service->getGlobalStatistics();
        $endTime = microtime(true);

        $executionTime = $endTime - $startTime;

        // Performance requirement: < 1 second for percentile calculations
        $this->assertLessThan(1.0, $executionTime, "Percentile calculations took {$executionTime}s, should be under 1.0s");

        // Verify percentiles are reasonable
        $this->assertEquals(count($wages), $statistics['count']);
        $this->assertGreaterThan(1000, $statistics['p25']);
        $this->assertLessThan(5000, $statistics['p90']);
        $this->assertEquals($statistics['p50'], $statistics['median_cents']);
    }

    /** @test */
    public function database_query_efficiency_is_optimized(): void
    {
        // Create moderate dataset
        $this->createLargeWageReportDataset(300);

        // Clear cache
        Cache::flush();

        // Count queries executed
        DB::enableQueryLog();
        $this->service->getGlobalStatistics();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Should execute minimal queries (ideally 4-6 for all breakdowns)
        $this->assertLessThanOrEqual(8, count($queries),
            'Statistics calculation executed '.count($queries).' queries, should be optimized to <= 8');

        // Verify query structure contains expected PostgreSQL functions
        $mainQuery = $queries[0]['query'] ?? '';
        $this->assertStringContainsString('percentile_cont', $mainQuery, 'Should use PostgreSQL percentile functions');
        $this->assertStringContainsString('STDDEV', $mainQuery, 'Should calculate standard deviation');
    }

    /**
     * Create a large dataset of wage reports with realistic variation
     */
    private function createLargeWageReportDataset(int $count): void
    {
        $employmentTypes = ['full_time', 'part_time', 'seasonal', 'contract'];
        $jobTitles = ['Server', 'Cook', 'Cashier', 'Manager', 'Host', 'Barista', 'Kitchen Staff'];

        for ($i = 0; $i < $count; $i++) {
            WageReport::factory()->approved()->create([
                'user_id' => $this->user->id,
                'organization_id' => $this->organization->id,
                'location_id' => fake()->randomElement($this->locations)->id,
                'position_category_id' => fake()->randomElement($this->positionCategories)->id,
                'job_title' => fake()->randomElement($jobTitles),
                'employment_type' => fake()->randomElement($employmentTypes),
                'normalized_hourly_cents' => fake()->numberBetween(1200, 4000), // $12-$40/hour
                'effective_date' => fake()->dateTimeBetween('-2 years', 'now'),
                'tips_included' => fake()->boolean(30), // 30% chance
                'unionized' => fake()->boolean(20), // 20% chance
            ]);
        }
    }

    /**
     * Create a diverse dataset for filter testing
     */
    private function createDiverseWageReportDataset(int $count): void
    {
        $employmentTypes = ['full_time', 'part_time', 'seasonal', 'contract'];
        $jobTitles = ['Server', 'Cook', 'Cashier', 'Manager', 'Host', 'Barista'];

        for ($i = 0; $i < $count; $i++) {
            WageReport::factory()->approved()->create([
                'user_id' => $this->user->id,
                'organization_id' => $this->organization->id,
                'location_id' => fake()->randomElement($this->locations)->id,
                'position_category_id' => fake()->randomElement($this->positionCategories)->id,
                'job_title' => fake()->randomElement($jobTitles),
                'employment_type' => fake()->randomElement($employmentTypes),
                'normalized_hourly_cents' => fake()->numberBetween(1000, 5000), // $10-$50/hour
                'effective_date' => fake()->dateTimeBetween('-1 year', 'now'),
                'currency' => 'USD',
                'tips_included' => fake()->boolean(),
                'unionized' => fake()->boolean(),
            ]);
        }
    }
}
