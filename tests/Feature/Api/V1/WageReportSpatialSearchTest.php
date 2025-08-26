<?php

namespace Tests\Feature\Api\V1;

use App\Models\Industry;
use App\Models\Location;
use App\Models\Organization;
use App\Models\WageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WageReportSpatialSearchTest extends TestCase
{
    use RefreshDatabase;

    private Industry $industry;

    private Organization $organization;

    private Location $nycLocation;

    private Location $laLocation;

    private Location $chicagoLocation;

    protected function setUp(): void
    {
        parent::setUp();

        // Create industry
        $this->industry = Industry::factory()->create([
            'name' => 'Food Service',
            'slug' => 'food-service',
        ]);

        // Create organization
        $this->organization = Organization::factory()->create([
            'name' => 'Test Restaurant Chain',
            'slug' => 'test-restaurant-chain',
            'primary_industry_id' => $this->industry->id,
        ]);

        // Create NYC location (40.7128, -74.0060)
        $this->nycLocation = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Test NYC Location',
            'city' => 'New York',
            'state_province' => 'NY',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
        ]);

        // Create LA location (34.0522, -118.2437)
        $this->laLocation = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Test LA Location',
            'city' => 'Los Angeles',
            'state_province' => 'CA',
            'latitude' => 34.0522,
            'longitude' => -118.2437,
        ]);

        // Create Chicago location (41.8781, -87.6298)
        $this->chicagoLocation = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Chicago Location',
            'city' => 'Chicago',
            'state_province' => 'IL',
            'latitude' => 41.8781,
            'longitude' => -87.6298,
        ]);

        // Wait a moment for PostGIS to process the geometry updates
        sleep(1);
    }

    public function test_can_search_wage_reports_near_location(): void
    {
        // Create wage reports at different locations
        $nycReport = WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->nycLocation->id,
            'job_title' => 'Server',
            'amount_cents' => 1500,
        ]);

        $laReport = WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->laLocation->id,
            'job_title' => 'Server',
            'amount_cents' => 1800,
        ]);

        // Search near NYC (within 100km radius should only find NYC report)
        $response = $this->getJson('/api/v1/wage-reports?near=40.7128,-74.0060&radius_km=100');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $nycReport->id)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'job_title',
                        'distance_meters',
                        'location',
                        'organization',
                    ],
                ],
            ]);

        // Verify distance field is included
        $responseData = $response->json();
        $this->assertArrayHasKey('distance_meters', $responseData['data'][0]);
        $this->assertIsInt($responseData['data'][0]['distance_meters']);
        $this->assertLessThan(50000, $responseData['data'][0]['distance_meters']); // Within 50km of center
    }

    public function test_can_search_wage_reports_with_large_radius(): void
    {
        // Create wage reports at NYC and a nearby location within 100km radius
        // Create a location in Newark, NJ (about 15km from NYC)
        $nearbyLocation = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Newark Location',
            'city' => 'Newark',
            'state_province' => 'NJ',
            'latitude' => 40.7357,
            'longitude' => -74.1724,
        ]);

        $nycReport = WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->nycLocation->id,
            'job_title' => 'Server',
        ]);

        $nearbyReport = WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $nearbyLocation->id,
            'job_title' => 'Cook',
        ]);

        // Search near NYC with large radius (100km should find both NYC and Newark)
        $response = $this->getJson('/api/v1/wage-reports?near=40.7128,-74.0060&radius_km=100');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $responseData = $response->json();

        // Should be ordered by distance (NYC first, then Newark)
        $this->assertEquals($nycReport->id, $responseData['data'][0]['id']);
        $this->assertEquals($nearbyReport->id, $responseData['data'][1]['id']);

        // Both should have distance_meters
        $this->assertArrayHasKey('distance_meters', $responseData['data'][0]);
        $this->assertArrayHasKey('distance_meters', $responseData['data'][1]);
        $this->assertLessThan($responseData['data'][1]['distance_meters'], $responseData['data'][0]['distance_meters']);
    }

    public function test_spatial_search_excludes_distant_locations(): void
    {
        // Create wage reports at NYC and LA
        WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->nycLocation->id,
            'job_title' => 'Server',
        ]);

        WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->laLocation->id,
            'job_title' => 'Cook',
        ]);

        // Search near NYC with small radius (100km should only find NYC)
        $response = $this->getJson('/api/v1/wage-reports?near=40.7128,-74.0060&radius_km=100');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.job_title', 'Server'); // NYC report
    }

    public function test_can_sort_by_closest_when_using_spatial_search(): void
    {
        // Create wage reports at different distances from NYC
        $nycReport = WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->nycLocation->id,
            'job_title' => 'Server',
        ]);

        // Create a location in Long Island (about 50km from NYC)
        $longIslandLocation = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Long Island Location',
            'city' => 'Hempstead',
            'state_province' => 'NY',
            'latitude' => 40.7062,
            'longitude' => -73.6187,
        ]);

        $distantReport = WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $longIslandLocation->id,
            'job_title' => 'Cook',
        ]);

        // Search with sort=closest
        $response = $this->getJson('/api/v1/wage-reports?near=40.7128,-74.0060&radius_km=100&sort=closest');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $responseData = $response->json();

        // Should be ordered by distance (closest first)
        $this->assertEquals($nycReport->id, $responseData['data'][0]['id']);
        $this->assertEquals($distantReport->id, $responseData['data'][1]['id']);

        // Verify distances are in ascending order
        $this->assertLessThan($responseData['data'][1]['distance_meters'], $responseData['data'][0]['distance_meters']);
    }

    public function test_spatial_search_validates_coordinates(): void
    {
        // Invalid latitude (> 90)
        $response = $this->getJson('/api/v1/wage-reports?near=100.0,-74.0060');
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['near']);

        // Invalid longitude (< -180)
        $response = $this->getJson('/api/v1/wage-reports?near=40.7128,-200.0');
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['near']);

        // Invalid format
        $response = $this->getJson('/api/v1/wage-reports?near=invalid,coords');
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['near']);
    }

    public function test_spatial_search_validates_radius(): void
    {
        // Radius too small
        $response = $this->getJson('/api/v1/wage-reports?near=40.7128,-74.0060&radius_km=0.05');
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['radius_km']);

        // Radius too large
        $response = $this->getJson('/api/v1/wage-reports?near=40.7128,-74.0060&radius_km=150');
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['radius_km']);
    }

    public function test_spatial_search_without_near_parameter_works_normally(): void
    {
        // Create wage report
        $report = WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->nycLocation->id,
        ]);

        // Search without spatial parameters
        $response = $this->getJson('/api/v1/wage-reports');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $report->id);

        // Should not include distance_meters
        $responseData = $response->json();
        $this->assertArrayNotHasKey('distance_meters', $responseData['data'][0]);
    }

    public function test_spatial_search_works_with_other_filters(): void
    {
        // Create reports with different job titles at NYC location
        $serverReport = WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->nycLocation->id,
            'job_title' => 'Server',
            'amount_cents' => 1500,
        ]);

        $cookReport = WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->nycLocation->id,
            'job_title' => 'Cook',
            'amount_cents' => 1800,
        ]);

        // Search near NYC with job title filter
        $response = $this->getJson('/api/v1/wage-reports?near=40.7128,-74.0060&radius_km=100&job_title=Server');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $serverReport->id)
            ->assertJsonPath('data.0.job_title', 'Server');

        // Verify distance is included
        $responseData = $response->json();
        $this->assertArrayHasKey('distance_meters', $responseData['data'][0]);
    }

    public function test_spatial_search_performance(): void
    {
        // Create multiple wage reports at various locations
        for ($i = 0; $i < 50; $i++) {
            WageReport::factory()->approved()->create([
                'organization_id' => $this->organization->id,
                'location_id' => $this->nycLocation->id,
            ]);
        }

        for ($i = 0; $i < 50; $i++) {
            WageReport::factory()->approved()->create([
                'organization_id' => $this->organization->id,
                'location_id' => $this->chicagoLocation->id,
            ]);
        }

        // Measure query performance
        $start = microtime(true);
        $response = $this->getJson('/api/v1/wage-reports?near=40.7128,-74.0060&radius_km=100');
        $duration = (microtime(true) - $start) * 1000; // Convert to milliseconds

        $response->assertStatus(200);

        // Query should complete within 200ms (project requirement)
        $this->assertLessThan(200, $duration, "Spatial query took {$duration}ms, exceeding 200ms requirement");
    }

    public function test_distance_calculation_accuracy(): void
    {
        // Create wage report at known location
        $report = WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->nycLocation->id,
        ]);

        // Search from a point ~1km away from NYC location
        // Using coordinates ~1km north of NYC: 40.7218, -74.0060
        $response = $this->getJson('/api/v1/wage-reports?near=40.7218,-74.0060&radius_km=5');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $responseData = $response->json();
        $distance = $responseData['data'][0]['distance_meters'];

        // Distance should be approximately 1000 meters (±500m tolerance for coordinate precision)
        $this->assertGreaterThan(500, $distance, 'Distance should be greater than 500m');
        $this->assertLessThan(1500, $distance, 'Distance should be less than 1500m');
    }
}
