<?php

namespace Tests\Unit\Services;

use App\Models\Industry;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use App\Models\WageReport;
use App\Services\WageStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WageStatisticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private WageStatisticsService $service;

    private Organization $organization;

    private Location $location;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new WageStatisticsService;

        // Create test data
        $industry = Industry::factory()->create();
        $this->organization = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);
        $this->location = Location::factory()->active()->verified()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_calculates_global_statistics_correctly(): void
    {
        // Clear any existing data from other tests
        WageReport::truncate();

        // Create wage reports with known values
        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'job_title' => 'Server',
            'employment_type' => 'part_time',
            'normalized_hourly_cents' => 1500,
        ]);

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'job_title' => 'Cook',
            'employment_type' => 'full_time',
            'normalized_hourly_cents' => 2000,
        ]);

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'job_title' => 'Manager',
            'employment_type' => 'full_time',
            'normalized_hourly_cents' => 2500,
        ]);

        // Clear cache to ensure fresh data
        Cache::flush();
        $statistics = $this->service->getGlobalStatistics();

        $this->assertEquals(3, $statistics['count']);
        $this->assertEquals(2000, $statistics['average_cents']); // (1500+2000+2500)/3
        $this->assertEquals(2000, $statistics['median_cents']); // Middle value
        $this->assertEquals(1500, $statistics['min_cents']);
        $this->assertEquals(2500, $statistics['max_cents']);

        // Check percentiles (p50 should equal median)
        $this->assertEquals(2000, $statistics['p50']);

        // Check employment type breakdown
        $this->assertCount(2, $statistics['employment_types']);

        $employmentTypes = collect($statistics['employment_types'])->keyBy('type');
        $this->assertEquals(2, $employmentTypes['full_time']['count']);
        $this->assertEquals(1, $employmentTypes['part_time']['count']);

        // Check job title breakdown
        $this->assertCount(3, $statistics['job_titles']);

        $jobTitles = collect($statistics['job_titles'])->keyBy('title');
        $this->assertArrayHasKey('Server', $jobTitles->toArray());
        $this->assertArrayHasKey('Cook', $jobTitles->toArray());
        $this->assertArrayHasKey('Manager', $jobTitles->toArray());

        // Check geographic distribution
        $this->assertCount(1, $statistics['geographic_distribution']);
        $this->assertEquals($this->location->city, $statistics['geographic_distribution'][0]['city']);
        $this->assertEquals($this->location->state_province, $statistics['geographic_distribution'][0]['state']);
        $this->assertEquals(3, $statistics['geographic_distribution'][0]['count']);
    }

    /** @test */
    public function it_calculates_location_specific_statistics(): void
    {
        // Create wage reports for the location
        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'normalized_hourly_cents' => 1600,
        ]);

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'normalized_hourly_cents' => 1800,
        ]);

        // Create wage report for different location (should be excluded)
        $otherLocation = Location::factory()->active()->create();
        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $otherLocation->id,
            'normalized_hourly_cents' => 3000,
        ]);

        $statistics = $this->service->getLocationStatistics($this->location->id);

        $this->assertEquals(2, $statistics['count']);
        $this->assertEquals(1700, $statistics['average_cents']); // (1600+1800)/2
        $this->assertEquals(1600, $statistics['min_cents']);
        $this->assertEquals(1800, $statistics['max_cents']);
    }

    /** @test */
    public function it_calculates_organization_specific_statistics(): void
    {
        // Create location for this organization
        $location2 = Location::factory()->active()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Create wage reports for the organization
        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'normalized_hourly_cents' => 1500,
        ]);

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $location2->id,
            'normalized_hourly_cents' => 2500,
        ]);

        // Create wage report for different organization (should be excluded)
        $otherOrg = Organization::factory()->active()->create();
        $otherLocation = Location::factory()->active()->create([
            'organization_id' => $otherOrg->id,
        ]);

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $otherOrg->id,
            'location_id' => $otherLocation->id,
            'normalized_hourly_cents' => 5000,
        ]);

        $statistics = $this->service->getOrganizationStatistics($this->organization->id);

        $this->assertEquals(2, $statistics['count']);
        $this->assertEquals(2000, $statistics['average_cents']); // (1500+2500)/2
        $this->assertEquals(1500, $statistics['min_cents']);
        $this->assertEquals(2500, $statistics['max_cents']);
    }

    /** @test */
    public function it_returns_empty_statistics_when_no_data(): void
    {
        $statistics = $this->service->getGlobalStatistics();

        $this->assertEquals(0, $statistics['count']);
        $this->assertEquals(0, $statistics['average_cents']);
        $this->assertEquals(0, $statistics['median_cents']);
        $this->assertEquals(0, $statistics['min_cents']);
        $this->assertEquals(0, $statistics['max_cents']);
        $this->assertEquals(0, $statistics['std_deviation_cents']);
        $this->assertEquals([], $statistics['employment_types']);
        $this->assertEquals([], $statistics['job_titles']);
        $this->assertEquals([], $statistics['geographic_distribution']);
    }

    /** @test */
    public function it_excludes_non_approved_wage_reports(): void
    {
        // Clear any existing data from other tests
        WageReport::truncate();

        // Create approved wage report
        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'normalized_hourly_cents' => 1500,
        ]);

        // Create non-approved wage reports
        WageReport::factory()->pending()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'normalized_hourly_cents' => 5000,
        ]);

        WageReport::factory()->rejected()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'normalized_hourly_cents' => 100,
        ]);

        // Clear cache to ensure fresh data
        Cache::flush();
        $statistics = $this->service->getGlobalStatistics();

        // Should only count the approved wage report
        $this->assertEquals(1, $statistics['count']);
        $this->assertEquals(1500, $statistics['average_cents']);
        $this->assertEquals(1500, $statistics['min_cents']);
        $this->assertEquals(1500, $statistics['max_cents']);
    }

    /** @test */
    public function it_caches_statistics_results(): void
    {
        // Clear cache first
        Cache::flush();

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'normalized_hourly_cents' => 1500,
        ]);

        // First call should cache the result
        $this->service->getGlobalStatistics();
        $this->assertTrue(Cache::has('wage_stats_global'));

        $this->service->getLocationStatistics($this->location->id);
        $this->assertTrue(Cache::has("wage_stats_location_{$this->location->id}"));

        $this->service->getOrganizationStatistics($this->organization->id);
        $this->assertTrue(Cache::has("wage_stats_org_{$this->organization->id}"));
    }

    /** @test */
    public function it_clears_cache_correctly(): void
    {
        // Set up some cached data
        Cache::put('wage_stats_global', ['test' => 'data'], 900);
        Cache::put("wage_stats_location_{$this->location->id}", ['test' => 'data'], 900);
        Cache::put("wage_stats_org_{$this->organization->id}", ['test' => 'data'], 900);

        // Clear specific cache
        $this->service->clearCache('global');
        $this->assertFalse(Cache::has('wage_stats_global'));

        $this->service->clearCache('location', $this->location->id);
        $this->assertFalse(Cache::has("wage_stats_location_{$this->location->id}"));

        $this->service->clearCache('organization', $this->organization->id);
        $this->assertFalse(Cache::has("wage_stats_org_{$this->organization->id}"));
    }

    /** @test */
    public function it_handles_postgresql_percentile_functions(): void
    {
        // Create wage reports with known distribution for percentile testing
        $wages = [1000, 1200, 1400, 1600, 1800, 2000, 2200, 2400, 2600, 2800];

        foreach ($wages as $wage) {
            WageReport::factory()->approved()->create([
                'user_id' => $this->user->id,
                'organization_id' => $this->organization->id,
                'location_id' => $this->location->id,
                'normalized_hourly_cents' => $wage,
            ]);
        }

        $statistics = $this->service->getGlobalStatistics();

        $this->assertEquals(10, $statistics['count']);

        // Verify percentiles are reasonable for our data set
        $this->assertGreaterThanOrEqual(1000, $statistics['p25']);
        $this->assertLessThanOrEqual(1600, $statistics['p25']);

        $this->assertGreaterThanOrEqual(1600, $statistics['p50']);
        $this->assertLessThanOrEqual(2200, $statistics['p50']);

        $this->assertGreaterThanOrEqual(2200, $statistics['p75']);
        $this->assertLessThanOrEqual(2800, $statistics['p75']);

        $this->assertGreaterThanOrEqual(2400, $statistics['p90']);
        $this->assertLessThanOrEqual(2800, $statistics['p90']);

        // Verify p50 equals median
        $this->assertEquals($statistics['p50'], $statistics['median_cents']);
    }

    /** @test */
    public function it_limits_job_titles_to_top_10(): void
    {
        // Create 12 different job titles
        for ($i = 1; $i <= 12; $i++) {
            WageReport::factory()->approved()->create([
                'user_id' => $this->user->id,
                'organization_id' => $this->organization->id,
                'location_id' => $this->location->id,
                'job_title' => "Job Title {$i}",
                'normalized_hourly_cents' => 1500 + ($i * 100),
            ]);
        }

        $statistics = $this->service->getGlobalStatistics();

        // Should only return top 10 job titles
        $this->assertCount(10, $statistics['job_titles']);

        // Should be ordered by count (descending), but since each has count=1,
        // they should all be present in the result
        foreach ($statistics['job_titles'] as $jobTitle) {
            $this->assertEquals(1, $jobTitle['count']);
        }
    }

    /** @test */
    public function it_limits_geographic_distribution_to_top_15(): void
    {
        // Create locations in 20 different cities
        for ($i = 1; $i <= 20; $i++) {
            $location = Location::factory()->active()->create([
                'organization_id' => $this->organization->id,
                'city' => "City {$i}",
                'state_province' => 'ST',
            ]);

            WageReport::factory()->approved()->create([
                'user_id' => $this->user->id,
                'organization_id' => $this->organization->id,
                'location_id' => $location->id,
                'normalized_hourly_cents' => 1500,
            ]);
        }

        $statistics = $this->service->getGlobalStatistics();

        // Should only return top 15 geographic locations
        $this->assertCount(15, $statistics['geographic_distribution']);

        foreach ($statistics['geographic_distribution'] as $location) {
            $this->assertEquals(1, $location['count']);
            $this->assertEquals('ST', $location['state']);
        }
    }
}
