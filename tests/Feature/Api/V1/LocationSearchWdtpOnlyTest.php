<?php

namespace Tests\Feature\Api\V1;

use App\Models\Industry;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocationSearchWdtpOnlyTest extends TestCase
{
    use RefreshDatabase;

    private string $endpoint = '/api/v1/locations/search';

    /** @test */
    public function it_returns_locations_matching_name_search(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'McDonald\'s Times Square',
            'latitude' => 40.7580,
            'longitude' => -73.9855,
            'status' => 'active',
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Starbucks Coffee',
            'latitude' => 40.7489,
            'longitude' => -73.9680,
            'status' => 'active',
        ]);

        $response = $this->getJson($this->endpoint . '?q=McDonald&lat=40.7128&lng=-74.0060&radius_km=10');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['name' => 'McDonald\'s Times Square']);
    }

    /** @test */
    public function it_filters_locations_within_radius(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        // Location within 5km of NYC center
        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Close Location',
            'latitude' => 40.7580, // ~4.8km from center
            'longitude' => -73.9855,
            'status' => 'active',
        ]);

        // Location beyond 5km of NYC center
        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Far Location',
            'latitude' => 40.6413, // ~10km from center
            'longitude' => -73.7781,
            'status' => 'active',
        ]);

        $response = $this->getJson($this->endpoint . '?q=Location&lat=40.7128&lng=-74.0060&radius_km=5');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['name' => 'Close Location']);
        $response->assertJsonMissing(['name' => 'Far Location']);
    }

    /** @test */
    public function it_calculates_distance_accurately(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Test Location',
            'latitude' => 40.7580,
            'longitude' => -73.9855,
            'status' => 'active',
        ]);

        $response = $this->getJson($this->endpoint . '?q=Test&lat=40.7128&lng=-74.0060&radius_km=10');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['distance_meters'],
            ],
        ]);

        // Distance should be approximately 7.1km (7100m) ±500m tolerance
        $distance = $response->json('data.0.distance_meters');
        $this->assertGreaterThan(6600, $distance);
        $this->assertLessThan(7600, $distance);
    }

    /** @test */
    public function it_sorts_results_by_relevance_score(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        // Perfect match, farther away
        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'McDonald\'s',
            'latitude' => 40.6413,
            'longitude' => -73.7781,
            'status' => 'active',
        ]);

        // Partial match, closer
        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'McDonald\'s Cafe',
            'latitude' => 40.7580,
            'longitude' => -73.9855,
            'status' => 'active',
        ]);

        $response = $this->getJson($this->endpoint . '?q=McDonald&lat=40.7128&lng=-74.0060&radius_km=15');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');

        // First result should have higher relevance_score
        $first = $response->json('data.0');
        $second = $response->json('data.1');
        $this->assertGreaterThan($second['relevance_score'], $first['relevance_score']);
    }

    /** @test */
    public function it_paginates_results_correctly(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        // Create 25 locations
        for ($i = 0; $i < 25; $i++) {
            Location::factory()->create([
                'organization_id' => $org->id,
                'name' => "McDonald's Location {$i}",
                'latitude' => 40.7128 + ($i * 0.001),
                'longitude' => -74.0060 + ($i * 0.001),
                'status' => 'active',
            ]);
        }

        $response = $this->getJson($this->endpoint . '?q=McDonald&lat=40.7128&lng=-74.0060&radius_km=10&per_page=10');

        $response->assertStatus(200);
        $response->assertJsonCount(10, 'data');
        $response->assertJsonPath('meta.total', 25);
        $response->assertJsonPath('meta.per_page', 10);
        $response->assertJsonPath('meta.current_page', 1);
    }

    /** @test */
    public function it_includes_pagination_metadata(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->count(5)->create([
            'organization_id' => $org->id,
            'name' => 'Test Location',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        $response = $this->getJson($this->endpoint . '?q=Test&lat=40.7128&lng=-74.0060&radius_km=10&per_page=2&page=2');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.current_page', 2);
        $response->assertJsonPath('meta.per_page', 2);
    }

    /** @test */
    public function it_returns_correct_response_format(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Test Location',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        $response = $this->getJson($this->endpoint . '?q=Test&lat=40.7128&lng=-74.0060&radius_km=10');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'source',
                    'location_id',
                    'osm_id',
                    'osm_type',
                    'name',
                    'latitude',
                    'longitude',
                    'has_wage_data',
                    'wage_reports_count',
                    'address',
                    'organization',
                    'distance_meters',
                    'relevance_score',
                ],
            ],
            'meta' => [
                'total',
                'wdtp_count',
                'osm_count',
                'search_query',
                'search_type',
                'center',
                'radius_km',
            ],
        ]);
    }

    /** @test */
    public function it_populates_meta_object_correctly(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->count(3)->create([
            'organization_id' => $org->id,
            'name' => 'Test Location',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        $response = $this->getJson($this->endpoint . '?q=Test&lat=40.7128&lng=-74.0060&radius_km=5');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.search_query', 'Test');
        $response->assertJsonPath('meta.wdtp_count', 3);
        $response->assertJsonPath('meta.osm_count', 0);
        $response->assertJsonPath('meta.center.latitude', 40.7128);
        $response->assertJsonPath('meta.center.longitude', -74.0060);
        $response->assertJsonPath('meta.radius_km', 5);
    }

    /** @test */
    public function it_includes_organization_relationship(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'name' => 'McDonald\'s Corporation',
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Test Location',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        $response = $this->getJson($this->endpoint . '?q=Test&lat=40.7128&lng=-74.0060&radius_km=10');

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'McDonald\'s Corporation']);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'organization' => ['name', 'slug'],
                ],
            ],
        ]);
    }

    /** @test */
    public function it_returns_empty_result_set_when_no_matches(): void
    {
        $response = $this->getJson($this->endpoint . '?q=NonexistentLocation&lat=40.7128&lng=-74.0060&radius_km=10');

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
        $response->assertJsonPath('meta.total', 0);
        $response->assertJsonPath('meta.wdtp_count', 0);
    }

    /** @test */
    public function it_performs_text_search_accurately(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Starbucks Coffee',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'McDonald\'s Restaurant',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        $response = $this->getJson($this->endpoint . '?q=Starbucks&lat=40.7128&lng=-74.0060&radius_km=10');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['name' => 'Starbucks Coffee']);
        $response->assertJsonMissing(['name' => 'McDonald\'s Restaurant']);
    }

    /** @test */
    public function it_handles_multi_word_search_queries(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Starbucks Coffee Times Square',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        $response = $this->getJson($this->endpoint . '?q=Starbucks Times&lat=40.7128&lng=-74.0060&radius_km=10');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['name' => 'Starbucks Coffee Times Square']);
    }

    /** @test */
    public function it_excludes_inactive_locations_from_results(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Active Location',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Inactive Location',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'inactive',
        ]);

        $response = $this->getJson($this->endpoint . '?q=Location&lat=40.7128&lng=-74.0060&radius_km=10');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['name' => 'Active Location']);
        $response->assertJsonMissing(['name' => 'Inactive Location']);
    }

    /** @test */
    public function it_returns_source_as_wdtp_for_all_results(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->count(3)->create([
            'organization_id' => $org->id,
            'name' => 'Test Location',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        $response = $this->getJson($this->endpoint . '?q=Test&lat=40.7128&lng=-74.0060&radius_km=10');

        $response->assertStatus(200);
        $data = $response->json('data');

        foreach ($data as $location) {
            $this->assertEquals('wdtp', $location['source']);
        }
    }

    /** @test */
    public function it_handles_search_by_address_components(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Test Location',
            'address_line_1' => '123 Broadway',
            'city' => 'New York',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        $response = $this->getJson($this->endpoint . '?q=Broadway&lat=40.7128&lng=-74.0060&radius_km=10');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    /** @test */
    public function it_handles_search_by_city_name(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Test Location',
            'city' => 'Manhattan',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        $response = $this->getJson($this->endpoint . '?q=Manhattan&lat=40.7128&lng=-74.0060&radius_km=10');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    /** @test */
    public function it_returns_relevance_score_for_all_results(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->count(3)->create([
            'organization_id' => $org->id,
            'name' => 'McDonald\'s',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        $response = $this->getJson($this->endpoint . '?q=McDonald&lat=40.7128&lng=-74.0060&radius_km=10');

        $response->assertStatus(200);
        $data = $response->json('data');

        foreach ($data as $location) {
            $this->assertArrayHasKey('relevance_score', $location);
            $this->assertIsFloat($location['relevance_score']);
            $this->assertGreaterThanOrEqual(0, $location['relevance_score']);
            $this->assertLessThanOrEqual(1, $location['relevance_score']);
        }
    }

    /** @test */
    public function it_respects_default_radius_when_not_specified(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Test Location',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        $response = $this->getJson($this->endpoint . '?q=Test&lat=40.7128&lng=-74.0060');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.radius_km', 10); // Default is 10km
    }

    /** @test */
    public function it_performs_within_acceptable_time_limits(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        // Create 50 locations for performance testing
        for ($i = 0; $i < 50; $i++) {
            Location::factory()->create([
                'organization_id' => $org->id,
                'name' => "Test Location {$i}",
                'latitude' => 40.7128 + ($i * 0.01),
                'longitude' => -74.0060 + ($i * 0.01),
                'status' => 'active',
            ]);
        }

        $startTime = microtime(true);
        $response = $this->getJson($this->endpoint . '?q=Test&lat=40.7128&lng=-74.0060&radius_km=50');
        $endTime = microtime(true);

        $response->assertStatus(200);

        $duration = ($endTime - $startTime) * 1000; // Convert to milliseconds
        $this->assertLessThan(500, $duration, 'API response time should be < 500ms');
    }
}
