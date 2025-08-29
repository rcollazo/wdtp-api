<?php

namespace Tests\Feature\Api\V1;

use App\Models\Industry;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationIndexTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $secondOrganization;

    private Location $nycLocation;

    private Location $laLocation;

    private Location $chicagoLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $industry = Industry::factory()->create([
            'name' => 'Food Service',
            'slug' => 'food-service',
        ]);

        $this->organization = Organization::factory()->create([
            'name' => 'Test Restaurant Chain',
            'slug' => 'test-restaurant-chain',
            'primary_industry_id' => $industry->id,
        ]);

        $this->secondOrganization = Organization::factory()->create([
            'name' => 'Second Chain',
            'slug' => 'second-chain',
            'primary_industry_id' => $industry->id,
        ]);

        // Create test locations with realistic coordinates
        $this->nycLocation = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'NYC Times Square Location',
            'city' => 'New York',
            'state_province' => 'NY',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'is_active' => true,
            'is_verified' => true,
        ]);

        $this->laLocation = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'LA Hollywood Location',
            'city' => 'Los Angeles',
            'state_province' => 'CA',
            'latitude' => 34.0522,
            'longitude' => -118.2437,
            'is_active' => true,
            'is_verified' => true,
        ]);

        $this->chicagoLocation = Location::factory()->create([
            'organization_id' => $this->secondOrganization->id,
            'name' => 'Chicago Downtown Location',
            'city' => 'Chicago',
            'state_province' => 'IL',
            'latitude' => 41.8781,
            'longitude' => -87.6298,
            'is_active' => true,
            'is_verified' => true,
        ]);
    }

    // ========== Basic Endpoint Functionality ==========

    public function test_locations_index_returns_paginated_locations(): void
    {
        $response = $this->getJson('/api/v1/locations');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'address_line_1',
                        'city',
                        'state_province',
                        'latitude',
                        'longitude',
                        'is_active',
                        'is_verified',
                        'organization',
                    ],
                ],
                'links',
                'meta' => ['current_page', 'total', 'per_page'],
            ])
            ->assertJsonCount(3, 'data'); // All 3 locations should be present
    }

    public function test_locations_index_includes_organization_relationships(): void
    {
        $response = $this->getJson('/api/v1/locations');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'organization' => [
                            'id',
                            'name',
                            'slug',
                        ],
                    ],
                ],
            ]);

        // Verify organization data is loaded correctly
        $locationData = $response->json('data');
        foreach ($locationData as $location) {
            $this->assertNotNull($location['organization']);
            $this->assertArrayHasKey('name', $location['organization']);
        }
    }

    public function test_locations_index_respects_per_page_limits(): void
    {
        // Create additional locations to test pagination
        Location::factory()->count(25)->create([
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/locations?per_page=10');

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10);
    }

    public function test_locations_index_handles_empty_results_gracefully(): void
    {
        // Delete all locations
        Location::query()->delete();

        $response = $this->getJson('/api/v1/locations');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    // ========== Spatial Search Core Functionality ==========

    public function test_spatial_search_with_near_parameter(): void
    {
        // Search near NYC coordinates - should find NYC location
        $response = $this->getJson('/api/v1/locations?near=40.7128,-74.0060&radius_km=10');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'NYC Times Square Location');
    }

    public function test_spatial_search_includes_distance_meters_in_response(): void
    {
        $response = $this->getJson('/api/v1/locations?near=40.7128,-74.0060&radius_km=10');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['distance_meters'],
                ],
            ]);

        $locationData = $response->json('data.0');
        $this->assertIsInt($locationData['distance_meters']);
        $this->assertGreaterThanOrEqual(0, $locationData['distance_meters']);
    }

    public function test_spatial_search_orders_by_distance(): void
    {
        // Create two locations close to each other near NYC for distance ordering test
        $closeLocation1 = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Close Location 1',
            'latitude' => 40.7100, // Very close to NYC Times Square
            'longitude' => -74.0050,
            'is_active' => true,
        ]);

        $closeLocation2 = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Close Location 2',
            'latitude' => 40.7150, // Slightly further from search point
            'longitude' => -74.0070,
            'is_active' => true,
        ]);

        // Search from a point that's closer to one than the other
        $response = $this->getJson('/api/v1/locations?near=40.7090,-74.0045&radius_km=5');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data'); // Should find original NYC + both close locations

        $allLocations = $response->json('data');

        // Verify locations are ordered by distance (ascending)
        for ($i = 0; $i < count($allLocations) - 1; $i++) {
            $this->assertLessThanOrEqual(
                $allLocations[$i + 1]['distance_meters'],
                $allLocations[$i]['distance_meters'],
                'Locations should be ordered by distance'
            );
        }
    }

    public function test_spatial_search_respects_radius_km_parameter(): void
    {
        // Search with small radius from NYC - should only find NYC location
        $response = $this->getJson('/api/v1/locations?near=40.7128,-74.0060&radius_km=1');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'NYC Times Square Location');

        // Create additional locations within various distances to test radius
        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Medium Distance Location',
            'latitude' => 40.75, // ~5km from NYC
            'longitude' => -74.0,
            'is_active' => true,
        ]);

        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Far Distance Location',
            'latitude' => 40.9, // ~20km from NYC
            'longitude' => -73.8,
            'is_active' => true,
        ]);

        // Search with medium radius - should find nearby locations
        $response = $this->getJson('/api/v1/locations?near=40.7128,-74.0060&radius_km=10');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data'); // Should find NYC + Medium Distance

        // Search with large radius - should find more locations
        $response = $this->getJson('/api/v1/locations?near=40.7128,-74.0060&radius_km=50');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data'); // Should find NYC + Medium + Far (LA/Chicago too far)
    }

    public function test_spatial_search_default_10km_radius_when_not_specified(): void
    {
        // Search near NYC without radius parameter - should use default 10km
        $response = $this->getJson('/api/v1/locations?near=40.7128,-74.0060');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data'); // Only NYC should be within default 10km

        // The distance should be included and should be reasonable for same coordinates
        $locationData = $response->json('data.0');
        $this->assertArrayHasKey('distance_meters', $locationData);
        $this->assertLessThan(1000, $locationData['distance_meters']); // Should be very close
    }

    // ========== Authentication Flows ==========

    public function test_locations_accessible_without_authentication(): void
    {
        // Public endpoint - should work without authentication
        $response = $this->getJson('/api/v1/locations');

        $response->assertStatus(200);
    }

    public function test_locations_works_with_bearer_token_authentication(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/locations');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_locations_works_with_cookie_csrf_authentication(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/v1/locations');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    // ========== Request Validation & Error Handling ==========

    public function test_invalid_coordinate_format_returns_422(): void
    {
        $response = $this->getJson('/api/v1/locations?near=invalid-coordinates');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['near']);
    }

    public function test_radius_below_minimum_returns_422(): void
    {
        $response = $this->getJson('/api/v1/locations?near=40.7128,-74.0060&radius_km=0.05');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['radius_km']);
    }

    public function test_radius_above_maximum_returns_422(): void
    {
        $response = $this->getJson('/api/v1/locations?near=40.7128,-74.0060&radius_km=100');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['radius_km']);
    }

    public function test_invalid_organization_id_returns_422(): void
    {
        $response = $this->getJson('/api/v1/locations?organization_id=99999');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['organization_id']);
    }

    public function test_per_page_above_maximum_returns_422(): void
    {
        $response = $this->getJson('/api/v1/locations?per_page=150');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_invalid_coordinates_out_of_bounds_returns_422(): void
    {
        // Note: Currently the validation only checks format, not coordinate bounds
        // This is a placeholder test for when bounds validation is implemented

        // For now, test malformed coordinates that don't match regex
        $response = $this->getJson('/api/v1/locations?near=abc,def');
        $response->assertStatus(422);

        // Test missing longitude
        $response = $this->getJson('/api/v1/locations?near=40.7128');
        $response->assertStatus(422);
    }

    // ========== Performance & Edge Cases ==========

    public function test_spatial_queries_complete_under_500ms(): void
    {
        // Create additional locations to stress test performance
        Location::factory()->count(100)->create([
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $startTime = microtime(true);
        $response = $this->getJson('/api/v1/locations?near=40.7128,-74.0060&radius_km=10');
        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertLessThan(500, $duration, 'Spatial query exceeded 500ms requirement');
    }

    public function test_extreme_coordinates_handled_correctly(): void
    {
        // Test coordinates at poles (within max radius limit)
        $response = $this->getJson('/api/v1/locations?near=90.0,0.0&radius_km=50');
        $response->assertStatus(200);

        // Test coordinates at date line
        $response = $this->getJson('/api/v1/locations?near=0.0,180.0&radius_km=50');
        $response->assertStatus(200);

        // Test coordinates at antimeridian
        $response = $this->getJson('/api/v1/locations?near=0.0,-180.0&radius_km=50');
        $response->assertStatus(200);
    }

    public function test_large_radius_queries_perform_acceptably(): void
    {
        $startTime = microtime(true);
        $response = $this->getJson('/api/v1/locations?near=40.7128,-74.0060&radius_km=50');
        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertLessThan(1000, $duration, 'Large radius query took too long');
    }

    public function test_pagination_works_with_spatial_filtering(): void
    {
        // Create more locations near NYC
        Location::factory()->count(25)->create([
            'organization_id' => $this->organization->id,
            'latitude' => 40.7128 + (rand(-100, 100) / 10000), // Within ~1km of NYC
            'longitude' => -74.0060 + (rand(-100, 100) / 10000),
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/locations?near=40.7128,-74.0060&radius_km=10&per_page=10');

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonStructure(['links', 'meta']);

        // All results should have distance_meters
        $locationData = $response->json('data');
        foreach ($locationData as $location) {
            $this->assertArrayHasKey('distance_meters', $location);
        }
    }

    // ========== Organization Filtering Integration ==========

    public function test_organization_id_filter_works_correctly(): void
    {
        $response = $this->getJson("/api/v1/locations?organization_id={$this->organization->id}");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data'); // Should find NYC and LA locations

        $locationData = $response->json('data');
        foreach ($locationData as $location) {
            $this->assertEquals($this->organization->id, $location['organization']['id']);
        }
    }

    public function test_organization_filter_combines_with_spatial_search(): void
    {
        $response = $this->getJson("/api/v1/locations?near=40.7128,-74.0060&radius_km=10&organization_id={$this->organization->id}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data') // Should find only NYC location from our organization
            ->assertJsonPath('data.0.name', 'NYC Times Square Location')
            ->assertJsonPath('data.0.organization.id', $this->organization->id);

        // Should include distance_meters
        $this->assertArrayHasKey('distance_meters', $response->json('data.0'));
    }

    public function test_nonexistent_organization_returns_empty_results(): void
    {
        $response = $this->getJson('/api/v1/locations?organization_id=99999');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['organization_id']);
    }

    // ========== Response Format Validation ==========

    public function test_response_format_matches_location_resource(): void
    {
        $response = $this->getJson('/api/v1/locations');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'address_line_1',
                        'address_line_2',
                        'city',
                        'state_province',
                        'postal_code',
                        'country_code',
                        'phone',
                        'website_url',
                        'description',
                        'latitude',
                        'longitude',
                        'is_active',
                        'is_verified',
                        'created_at',
                        'organization',
                    ],
                ],
            ]);
    }

    public function test_spatial_response_includes_distance_field(): void
    {
        $response = $this->getJson('/api/v1/locations?near=40.7128,-74.0060');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['distance_meters'],
                ],
            ]);

        $locationData = $response->json('data.0');
        $this->assertIsInt($locationData['distance_meters']);
        $this->assertGreaterThanOrEqual(0, $locationData['distance_meters']);
    }

    public function test_non_spatial_response_excludes_distance_field(): void
    {
        $response = $this->getJson('/api/v1/locations');

        $response->assertStatus(200);

        $locationData = $response->json('data.0');
        $this->assertArrayNotHasKey('distance_meters', $locationData);
    }

    // ========== Edge Cases & Error Scenarios ==========

    public function test_inactive_locations_are_excluded(): void
    {
        // Create inactive location
        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'is_active' => false,
            'latitude' => 40.7128,
            'longitude' => -74.0060,
        ]);

        $response = $this->getJson('/api/v1/locations?near=40.7128,-74.0060&radius_km=1');

        // Should still only find the active NYC location
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'NYC Times Square Location');
    }

    public function test_coordinate_precision_is_maintained(): void
    {
        $response = $this->getJson('/api/v1/locations');

        $response->assertStatus(200);

        $nycLocation = collect($response->json('data'))->firstWhere('name', 'NYC Times Square Location');
        $this->assertEquals(40.7128, $nycLocation['latitude']);
        $this->assertEquals(-74.0060, $nycLocation['longitude']);
    }
}
