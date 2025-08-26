<?php

namespace Tests\Feature\Api\V1;

use App\Models\Industry;
use App\Models\Location;
use App\Models\Organization;
use App\Models\PositionCategory;
use App\Models\User;
use App\Models\WageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WageStatisticsFilteringTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Location $location;

    private PositionCategory $positionCategory;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $industry = Industry::factory()->create(['name' => 'Food Service']);

        $this->organization = Organization::factory()->active()->verified()->create([
            'name' => 'Test Restaurant',
            'primary_industry_id' => $industry->id,
        ]);

        $this->location = Location::factory()->active()->verified()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Downtown Location',
            'city' => 'New York',
            'state_province' => 'NY',
        ]);

        $this->positionCategory = PositionCategory::factory()->active()->create([
            'industry_id' => $industry->id,
            'name' => 'Server',
        ]);

        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_filters_statistics_by_date_range(): void
    {
        // Create wage reports across different dates
        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'normalized_hourly_cents' => 1500,
            'effective_date' => '2023-06-01',
        ]);

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'normalized_hourly_cents' => 2000,
            'effective_date' => '2024-06-01',
        ]);

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'normalized_hourly_cents' => 2500,
            'effective_date' => '2025-06-01',
        ]);

        // Test filtering by date range
        $response = $this->getJson('/api/v1/wage-reports/stats?date_from=2024-01-01&date_to=2024-12-31');

        $response->assertStatus(200);

        $data = $response->json('data');

        // Should only include the 2024 report
        $this->assertEquals(1, $data['count']);
        $this->assertEquals(2000, $data['average_cents']);
        $this->assertEquals(2000, $data['min_cents']);
        $this->assertEquals(2000, $data['max_cents']);
    }

    /** @test */
    public function it_filters_statistics_by_employment_type(): void
    {
        // Create wage reports with different employment types
        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'employment_type' => 'full_time',
            'normalized_hourly_cents' => 2000,
        ]);

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'employment_type' => 'part_time',
            'normalized_hourly_cents' => 1500,
        ]);

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'employment_type' => 'contract',
            'normalized_hourly_cents' => 3000,
        ]);

        // Test filtering by employment type
        $response = $this->getJson('/api/v1/wage-reports/stats?employment_type=full_time');

        $response->assertStatus(200);

        $data = $response->json('data');

        // Should only include the full_time report
        $this->assertEquals(1, $data['count']);
        $this->assertEquals(2000, $data['average_cents']);

        // Employment types breakdown should only show full_time
        $this->assertCount(1, $data['employment_types']);
        $this->assertEquals('full_time', $data['employment_types'][0]['type']);
        $this->assertEquals('Full Time', $data['employment_types'][0]['type_display']);
    }

    /** @test */
    public function it_filters_statistics_by_position_category(): void
    {
        // Create another position category
        $cookCategory = PositionCategory::factory()->active()->create([
            'industry_id' => $this->positionCategory->industry_id,
            'name' => 'Cook',
        ]);

        // Create wage reports with different position categories
        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'job_title' => 'Server',
            'normalized_hourly_cents' => 1500,
        ]);

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $cookCategory->id,
            'job_title' => 'Cook',
            'normalized_hourly_cents' => 1800,
        ]);

        // Test filtering by position category
        $response = $this->getJson("/api/v1/wage-reports/stats?position_category_id={$this->positionCategory->id}");

        $response->assertStatus(200);

        $data = $response->json('data');

        // Should only include the server report
        $this->assertEquals(1, $data['count']);
        $this->assertEquals(1500, $data['average_cents']);

        // Job titles should only show the filtered position
        $this->assertCount(1, $data['job_titles']);
        $this->assertEquals('Server', $data['job_titles'][0]['title']);
    }

    /** @test */
    public function it_filters_statistics_by_wage_range(): void
    {
        // Create wage reports with different amounts
        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'normalized_hourly_cents' => 1200, // $12.00
        ]);

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'normalized_hourly_cents' => 1800, // $18.00
        ]);

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'normalized_hourly_cents' => 2500, // $25.00
        ]);

        // Test filtering by wage range ($15-$22)
        $response = $this->getJson('/api/v1/wage-reports/stats?min_wage=15.00&max_wage=22.00');

        $response->assertStatus(200);

        $data = $response->json('data');

        // Should only include the $18.00 report
        $this->assertEquals(1, $data['count']);
        $this->assertEquals(1800, $data['average_cents']);
        $this->assertEquals(1800, $data['min_cents']);
        $this->assertEquals(1800, $data['max_cents']);
    }

    /** @test */
    public function it_filters_statistics_by_unionized_status(): void
    {
        // Create wage reports with different unionized status
        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'unionized' => true,
            'normalized_hourly_cents' => 2200,
        ]);

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'unionized' => false,
            'normalized_hourly_cents' => 1800,
        ]);

        // Test filtering by unionized status
        $response = $this->getJson('/api/v1/wage-reports/stats?unionized=1');

        $response->assertStatus(200);

        $data = $response->json('data');

        // Should only include the unionized report
        $this->assertEquals(1, $data['count']);
        $this->assertEquals(2200, $data['average_cents']);
    }

    /** @test */
    public function it_filters_statistics_by_tips_included(): void
    {
        // Create wage reports with different tips included status
        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'tips_included' => true,
            'normalized_hourly_cents' => 1200, // Lower base wage with tips
        ]);

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'tips_included' => false,
            'normalized_hourly_cents' => 2000, // Higher base wage without tips
        ]);

        // Test filtering by tips included
        $response = $this->getJson('/api/v1/wage-reports/stats?tips_included=0');

        $response->assertStatus(200);

        $data = $response->json('data');

        // Should only include the non-tips report
        $this->assertEquals(1, $data['count']);
        $this->assertEquals(2000, $data['average_cents']);
    }

    /** @test */
    public function it_combines_multiple_filters_correctly(): void
    {
        // Create diverse wage reports
        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'employment_type' => 'full_time',
            'normalized_hourly_cents' => 2000,
            'effective_date' => '2024-06-01',
            'unionized' => true,
        ]);

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'employment_type' => 'part_time',
            'normalized_hourly_cents' => 1800,
            'effective_date' => '2024-06-01',
            'unionized' => false,
        ]);

        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'employment_type' => 'full_time',
            'normalized_hourly_cents' => 2500,
            'effective_date' => '2023-06-01', // Different year
            'unionized' => true,
        ]);

        // Test combining multiple filters
        $filters = [
            'employment_type' => 'full_time',
            'date_from' => '2024-01-01',
            'date_to' => '2024-12-31',
            'unionized' => '1',
            'min_wage' => '18.00',
        ];

        $response = $this->getJson('/api/v1/wage-reports/stats?'.http_build_query($filters));

        $response->assertStatus(200);

        $data = $response->json('data');

        // Should only include the first report (full_time, 2024, unionized, >= $18)
        $this->assertEquals(1, $data['count']);
        $this->assertEquals(2000, $data['average_cents']);
    }

    /** @test */
    public function it_validates_filter_parameters_correctly(): void
    {
        // Test invalid date range
        $response = $this->getJson('/api/v1/wage-reports/stats?date_from=2024-12-31&date_to=2024-01-01');
        $response->assertStatus(422);

        // Test invalid wage range
        $response = $this->getJson('/api/v1/wage-reports/stats?min_wage=30.00&max_wage=20.00');
        $response->assertStatus(422);

        // Test invalid employment type
        $response = $this->getJson('/api/v1/wage-reports/stats?employment_type=invalid_type');
        $response->assertStatus(422);

        // Test invalid position category ID
        $response = $this->getJson('/api/v1/wage-reports/stats?position_category_id=99999');
        $response->assertStatus(422);

        // Test invalid currency code
        $response = $this->getJson('/api/v1/wage-reports/stats?currency=INVALID');
        $response->assertStatus(422);
    }

    /** @test */
    public function filtered_results_are_cached_separately(): void
    {
        // Clear cache
        Cache::flush();

        // Create test data
        WageReport::factory()->approved()->count(3)->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'employment_type' => 'full_time',
            'normalized_hourly_cents' => 2000,
        ]);

        WageReport::factory()->approved()->count(2)->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'employment_type' => 'part_time',
            'normalized_hourly_cents' => 1500,
        ]);

        // Make request without filters
        $response1 = $this->getJson('/api/v1/wage-reports/stats');
        $response1->assertStatus(200);
        $this->assertEquals(5, $response1->json('data.count'));

        // Make request with filters
        $response2 = $this->getJson('/api/v1/wage-reports/stats?employment_type=full_time');
        $response2->assertStatus(200);
        $this->assertEquals(3, $response2->json('data.count'));

        // Verify both results are cached and different
        $this->assertNotEquals($response1->json('data'), $response2->json('data'));

        // Second identical requests should use cache (faster)
        $startTime = microtime(true);
        $response3 = $this->getJson('/api/v1/wage-reports/stats');
        $cachedTime1 = microtime(true) - $startTime;

        $startTime = microtime(true);
        $response4 = $this->getJson('/api/v1/wage-reports/stats?employment_type=full_time');
        $cachedTime2 = microtime(true) - $startTime;

        // Cached responses should be identical and fast
        $this->assertEquals($response1->json('data'), $response3->json('data'));
        $this->assertEquals($response2->json('data'), $response4->json('data'));
        $this->assertLessThan(0.1, $cachedTime1);
        $this->assertLessThan(0.1, $cachedTime2);
    }

    /** @test */
    public function it_returns_empty_statistics_when_filters_match_no_data(): void
    {
        // Create some wage reports
        WageReport::factory()->approved()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'employment_type' => 'full_time',
            'normalized_hourly_cents' => 2000,
            'effective_date' => '2024-06-01',
        ]);

        // Apply filters that won't match any data
        $response = $this->getJson('/api/v1/wage-reports/stats?employment_type=contract');

        $response->assertStatus(200);

        $data = $response->json('data');

        // Should return empty statistics structure
        $this->assertEquals(0, $data['count']);
        $this->assertEquals(0, $data['average_cents']);
        $this->assertEquals(0, $data['median_cents']);
        $this->assertEquals([], $data['employment_types']);
        $this->assertEquals([], $data['job_titles']);
        $this->assertEquals([], $data['geographic_distribution']);
        $this->assertEquals('$0.00', $data['display']['average']);
    }
}
