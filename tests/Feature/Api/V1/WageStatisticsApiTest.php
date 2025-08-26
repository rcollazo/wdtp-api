<?php

namespace Tests\Feature\Api\V1;

use App\Models\Industry;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use App\Models\WageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WageStatisticsApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Location $location1;

    private Location $location2;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $industry = Industry::factory()->create(['name' => 'Food Service']);
        $this->organization = Organization::factory()->active()->verified()->create([
            'name' => 'Test Restaurant Chain',
            'primary_industry_id' => $industry->id,
        ]);

        $this->location1 = Location::factory()->active()->verified()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Downtown Location',
            'city' => 'New York',
            'state_province' => 'NY',
        ]);

        $this->location2 = Location::factory()->active()->verified()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Uptown Location',
            'city' => 'New York',
            'state_province' => 'NY',
        ]);

        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_returns_global_wage_statistics(): void
    {
        // Create wage reports with various amounts
        $wageReports = [
            WageReport::factory()->approved()->create([
                'user_id' => $this->user->id,
                'organization_id' => $this->organization->id,
                'location_id' => $this->location1->id,
                'job_title' => 'Server',
                'employment_type' => 'part_time',
                'normalized_hourly_cents' => 1500, // $15.00
            ]),
            WageReport::factory()->approved()->create([
                'user_id' => $this->user->id,
                'organization_id' => $this->organization->id,
                'location_id' => $this->location1->id,
                'job_title' => 'Cook',
                'employment_type' => 'full_time',
                'normalized_hourly_cents' => 1800, // $18.00
            ]),
            WageReport::factory()->approved()->create([
                'user_id' => $this->user->id,
                'organization_id' => $this->organization->id,
                'location_id' => $this->location2->id,
                'job_title' => 'Manager',
                'employment_type' => 'full_time',
                'normalized_hourly_cents' => 2500, // $25.00
            ]),
            // Add pending report (should be excluded)
            WageReport::factory()->pending()->create([
                'user_id' => $this->user->id,
                'organization_id' => $this->organization->id,
                'location_id' => $this->location2->id,
                'job_title' => 'Cashier',
                'employment_type' => 'part_time',
                'normalized_hourly_cents' => 1200, // $12.00
            ]),
        ];

        $response = $this->getJson('/api/v1/wage-reports/stats');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'count',
                'average_cents',
                'median_cents',
                'min_cents',
                'max_cents',
                'std_deviation_cents',
                'percentiles' => [
                    'p25',
                    'p50',
                    'p75',
                    'p90',
                ],
                'employment_types' => [
                    '*' => [
                        'type',
                        'type_display',
                        'count',
                        'average_cents',
                    ],
                ],
                'job_titles' => [
                    '*' => [
                        'title',
                        'count',
                        'average_cents',
                    ],
                ],
                'geographic_distribution' => [
                    '*' => [
                        'city',
                        'state',
                        'count',
                        'average_cents',
                    ],
                ],
                'display' => [
                    'average',
                    'median',
                    'min',
                    'max',
                    'std_deviation',
                ],
            ],
        ]);

        $data = $response->json('data');

        // Verify basic statistics (only approved reports)
        $this->assertEquals(3, $data['count']);
        $this->assertEquals(1500, $data['min_cents']);
        $this->assertEquals(2500, $data['max_cents']);
        $this->assertEquals(1800, $data['median_cents']); // Middle value
        $this->assertEquals(1933, $data['average_cents']); // (1500 + 1800 + 2500) / 3

        // Verify employment type breakdown
        $this->assertCount(2, $data['employment_types']);
        $employmentTypes = collect($data['employment_types'])->keyBy('type');

        $this->assertEquals(2, $employmentTypes['full_time']['count']);
        $this->assertEquals('Full Time', $employmentTypes['full_time']['type_display']);
        $this->assertEquals(1, $employmentTypes['part_time']['count']);
        $this->assertEquals('Part Time', $employmentTypes['part_time']['type_display']);

        // Verify job titles breakdown
        $this->assertCount(3, $data['job_titles']);
        $jobTitles = collect($data['job_titles'])->keyBy('title');

        $this->assertArrayHasKey('Server', $jobTitles->toArray());
        $this->assertArrayHasKey('Cook', $jobTitles->toArray());
        $this->assertArrayHasKey('Manager', $jobTitles->toArray());

        // Verify geographic distribution
        $this->assertCount(1, $data['geographic_distribution']);
        $this->assertEquals('New York', $data['geographic_distribution'][0]['city']);
        $this->assertEquals('NY', $data['geographic_distribution'][0]['state']);
        $this->assertEquals(3, $data['geographic_distribution'][0]['count']);

        // Verify display formatting
        $this->assertEquals('$19.33', $data['display']['average']);
        $this->assertEquals('$18.00', $data['display']['median']);
        $this->assertEquals('$15.00', $data['display']['min']);
        $this->assertEquals('$25.00', $data['display']['max']);
    }

    /** @test */
    public function it_returns_location_specific_wage_statistics(): void
    {
        // Create wage reports for location 1
        WageReport::factory()->approved()->count(2)->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location1->id,
            'normalized_hourly_cents' => 1600, // $16.00
        ]);

        // Create wage report for location 2 (should be excluded)
        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location2->id,
            'normalized_hourly_cents' => 2000, // $20.00
        ]);

        $response = $this->getJson("/api/v1/locations/{$this->location1->id}/wage-stats");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(2, $data['count']);
        $this->assertEquals(1600, $data['average_cents']);
        $this->assertEquals('$16.00', $data['display']['average']);
    }

    /** @test */
    public function it_returns_organization_specific_wage_statistics(): void
    {
        // Create wage reports for the organization across multiple locations
        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location1->id,
            'normalized_hourly_cents' => 1500,
        ]);

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location2->id,
            'normalized_hourly_cents' => 2000,
        ]);

        // Create report for different organization (should be excluded)
        $otherOrg = Organization::factory()->active()->create();
        $otherLocation = Location::factory()->active()->create([
            'organization_id' => $otherOrg->id,
        ]);

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $otherOrg->id,
            'location_id' => $otherLocation->id,
            'normalized_hourly_cents' => 3000,
        ]);

        // Test with organization ID
        $response = $this->getJson("/api/v1/organizations/{$this->organization->id}/wage-stats");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(2, $data['count']);
        $this->assertEquals(1750, $data['average_cents']); // (1500 + 2000) / 2

        // Test with organization slug
        $response = $this->getJson("/api/v1/organizations/{$this->organization->slug}/wage-stats");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(2, $data['count']);
    }

    /** @test */
    public function it_returns_422_when_no_wage_data_available(): void
    {
        // Test location with no wage reports
        $emptyLocation = Location::factory()->active()->create();

        $response = $this->getJson("/api/v1/locations/{$emptyLocation->id}/wage-stats");
        $response->assertStatus(422);
        $response->assertJson(['message' => 'No wage data available for this location']);

        // Test organization with no wage reports
        $emptyOrg = Organization::factory()->active()->create();

        $response = $this->getJson("/api/v1/organizations/{$emptyOrg->id}/wage-stats");
        $response->assertStatus(422);
        $response->assertJson(['message' => 'No wage data available for this organization']);
    }

    /** @test */
    public function it_returns_404_for_non_existent_resources(): void
    {
        $response = $this->getJson('/api/v1/locations/99999/wage-stats');
        $response->assertStatus(404);

        $response = $this->getJson('/api/v1/organizations/99999/wage-stats');
        $response->assertStatus(404);

        $response = $this->getJson('/api/v1/organizations/non-existent-slug/wage-stats');
        $response->assertStatus(404);
    }

    /** @test */
    public function it_caches_statistics_for_performance(): void
    {
        // Clear cache to start fresh
        Cache::flush();

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location1->id,
            'normalized_hourly_cents' => 1500,
        ]);

        // First request should cache the result
        $this->getJson('/api/v1/wage-reports/stats');

        // Verify cache keys exist
        $this->assertTrue(Cache::has('wage_stats_global'));

        // Make another request - should use cached data
        $startTime = microtime(true);
        $response = $this->getJson('/api/v1/wage-reports/stats');
        $endTime = microtime(true);

        $response->assertStatus(200);

        // Second request should be faster (cached)
        $this->assertLessThan(0.1, $endTime - $startTime);
    }

    /** @test */
    public function it_calculates_percentiles_correctly(): void
    {
        // Create wage reports with known values for percentile testing
        $wages = [1000, 1200, 1500, 1800, 2000, 2200, 2500, 2800, 3000, 3500]; // $10-$35

        foreach ($wages as $wage) {
            WageReport::factory()->approved()->create([
                'user_id' => $this->user->id,
                'organization_id' => $this->organization->id,
                'location_id' => $this->location1->id,
                'normalized_hourly_cents' => $wage,
            ]);
        }

        $response = $this->getJson('/api/v1/wage-reports/stats');

        $response->assertStatus(200);

        $data = $response->json('data');
        $percentiles = $data['percentiles'];

        // With 10 values, percentiles should be reasonable approximations
        $this->assertGreaterThanOrEqual(1000, $percentiles['p25']);
        $this->assertLessThanOrEqual(1800, $percentiles['p25']);

        $this->assertGreaterThanOrEqual(1800, $percentiles['p50']);
        $this->assertLessThanOrEqual(2200, $percentiles['p50']);

        $this->assertGreaterThanOrEqual(2200, $percentiles['p75']);
        $this->assertLessThanOrEqual(2800, $percentiles['p75']);

        $this->assertGreaterThanOrEqual(2800, $percentiles['p90']);
        $this->assertLessThanOrEqual(3500, $percentiles['p90']);
    }

    /** @test */
    public function it_excludes_non_approved_wage_reports_from_statistics(): void
    {
        // Create approved wage report
        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location1->id,
            'normalized_hourly_cents' => 1500,
        ]);

        // Create non-approved wage reports
        WageReport::factory()->pending()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location1->id,
            'normalized_hourly_cents' => 5000,
        ]);

        WageReport::factory()->rejected()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location1->id,
            'normalized_hourly_cents' => 100,
        ]);

        $response = $this->getJson('/api/v1/wage-reports/stats');

        $response->assertStatus(200);

        $data = $response->json('data');

        // Should only count the approved wage report
        $this->assertEquals(1, $data['count']);
        $this->assertEquals(1500, $data['average_cents']);
        $this->assertEquals(1500, $data['min_cents']);
        $this->assertEquals(1500, $data['max_cents']);
    }

    /** @test */
    public function global_statistics_return_empty_structure_when_no_data(): void
    {
        // No wage reports created
        $response = $this->getJson('/api/v1/wage-reports/stats');

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertEquals(0, $data['count']);
        $this->assertEquals(0, $data['average_cents']);
        $this->assertEquals(0, $data['median_cents']);
        $this->assertEquals([], $data['employment_types']);
        $this->assertEquals([], $data['job_titles']);
        $this->assertEquals([], $data['geographic_distribution']);
        $this->assertEquals('$0.00', $data['display']['average']);
    }
}
