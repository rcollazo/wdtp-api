<?php

namespace Tests\Unit\Models;

use App\Models\Location;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test organization
        $this->organization = Organization::factory()->create();
    }

    /**
     * Test location model basic attributes and casting.
     */
    public function test_location_model_attributes_and_casting(): void
    {
        $location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'is_active' => true,
            'is_verified' => false,
        ]);

        // Test casts (coordinates are cast to decimal which becomes string in some contexts)
        $this->assertIsNumeric($location->latitude);
        $this->assertIsNumeric($location->longitude);
        $this->assertIsBool($location->is_active);
        $this->assertIsBool($location->is_verified);

        // Test precision
        $this->assertEquals(40.7128, $location->latitude);
        $this->assertEquals(-74.0060, $location->longitude);
    }

    /**
     * Test fillable and hidden fields.
     */
    public function test_fillable_and_hidden_fields(): void
    {
        $fillableFields = [
            'organization_id', 'name', 'slug', 'address_line_1', 'address_line_2',
            'city', 'state_province', 'postal_code', 'country_code', 'phone',
            'website_url', 'description', 'latitude', 'longitude', 'is_active',
            'is_verified', 'verification_notes', 'osm_id', 'osm_type', 'osm_data',
        ];

        $location = new Location;

        foreach ($fillableFields as $field) {
            $this->assertContains($field, $location->getFillable());
        }
    }

    /**
     * Test organization relationship.
     */
    public function test_organization_relationship(): void
    {
        $location = Location::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertInstanceOf(Organization::class, $location->organization);
        $this->assertEquals($this->organization->id, $location->organization->id);
        $this->assertEquals($this->organization->name, $location->organization->name);
    }

    /**
     * Test wageReports relationship exists (even though model not implemented yet).
     */
    public function test_wage_reports_relationship_exists(): void
    {
        $location = Location::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // The relationship should exist even if target model doesn't
        $this->assertTrue(method_exists($location, 'wageReports'));
    }

    /**
     * Test active scope filters correctly.
     */
    public function test_active_scope(): void
    {
        $activeLocation = Location::factory()->active()->create([
            'organization_id' => $this->organization->id,
        ]);
        $inactiveLocation = Location::factory()->inactive()->create([
            'organization_id' => $this->organization->id,
        ]);

        $activeLocations = Location::active()->get();

        $this->assertTrue($activeLocations->contains($activeLocation));
        $this->assertFalse($activeLocations->contains($inactiveLocation));
    }

    /**
     * Test verified scope filters correctly.
     */
    public function test_verified_scope(): void
    {
        $verifiedLocation = Location::factory()->verified()->create([
            'organization_id' => $this->organization->id,
        ]);
        $unverifiedLocation = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'is_verified' => false,
        ]);

        $verifiedLocations = Location::verified()->get();

        $this->assertTrue($verifiedLocations->contains($verifiedLocation));
        $this->assertFalse($verifiedLocations->contains($unverifiedLocation));
    }

    /**
     * Test near scope filters locations by distance.
     */
    public function test_near_scope_filters_by_distance(): void
    {
        // NYC coordinates
        $nycLat = 40.7128;
        $nycLon = -74.0060;

        // Create locations at known distances
        $nearLocation = Location::factory()->withCoordinates($nycLat + 0.001, $nycLon + 0.001)->create([
            'organization_id' => $this->organization->id,
        ]);

        $farLocation = Location::factory()->withCoordinates($nycLat + 1.0, $nycLon + 1.0)->create([
            'organization_id' => $this->organization->id,
        ]);

        // Test 1km radius - should only include near location
        $nearbyLocations = Location::near($nycLat, $nycLon, 1)->get();

        $this->assertTrue($nearbyLocations->contains($nearLocation));
        $this->assertFalse($nearbyLocations->contains($farLocation));

        // Test 200km radius - should include both
        $widerSearch = Location::near($nycLat, $nycLon, 200)->get();

        $this->assertTrue($widerSearch->contains($nearLocation));
        $this->assertTrue($widerSearch->contains($farLocation));
    }

    /**
     * Test withDistance scope adds distance calculation.
     */
    public function test_with_distance_scope_adds_distance_calculation(): void
    {
        $location = Location::factory()->withCoordinates(40.7128, -74.0060)->create([
            'organization_id' => $this->organization->id,
        ]);

        $locationsWithDistance = Location::withDistance(40.7580, -73.9855)->get();

        $this->assertTrue(isset($locationsWithDistance->first()->distance_meters));
        $this->assertIsNumeric($locationsWithDistance->first()->distance_meters);
        $this->assertGreaterThan(0, $locationsWithDistance->first()->distance_meters);
    }

    /**
     * Test orderByDistance scope orders results correctly.
     */
    public function test_order_by_distance_scope(): void
    {
        $refLat = 40.7128;
        $refLon = -74.0060;

        // Create locations at different distances
        $location1 = Location::factory()->withCoordinates($refLat + 0.001, $refLon)->create([
            'organization_id' => $this->organization->id,
            'name' => 'Closest',
        ]);

        $location2 = Location::factory()->withCoordinates($refLat + 0.01, $refLon)->create([
            'organization_id' => $this->organization->id,
            'name' => 'Farthest',
        ]);

        $location3 = Location::factory()->withCoordinates($refLat + 0.005, $refLon)->create([
            'organization_id' => $this->organization->id,
            'name' => 'Middle',
        ]);

        $orderedLocations = Location::withDistance($refLat, $refLon)
            ->orderByDistance($refLat, $refLon)
            ->get();

        $this->assertEquals('Closest', $orderedLocations[0]->name);
        $this->assertEquals('Middle', $orderedLocations[1]->name);
        $this->assertEquals('Farthest', $orderedLocations[2]->name);
    }

    /**
     * Test search scope finds locations by name, address, and city.
     */
    public function test_search_scope(): void
    {
        $locations = [
            Location::factory()->create([
                'organization_id' => $this->organization->id,
                'name' => 'Downtown Store',
                'address_line_1' => '123 Main St',
                'city' => 'New York',
            ]),
            Location::factory()->create([
                'organization_id' => $this->organization->id,
                'name' => 'Uptown Branch',
                'address_line_1' => '456 Broadway',
                'city' => 'Albany',
            ]),
            Location::factory()->create([
                'organization_id' => $this->organization->id,
                'name' => 'Mall Location',
                'address_line_1' => '789 Oak Ave',
                'city' => 'Buffalo',
            ]),
        ];

        // Search by name
        $nameResults = Location::search('Downtown')->get();
        $this->assertCount(1, $nameResults);
        $this->assertEquals('Downtown Store', $nameResults->first()->name);

        // Search by address
        $addressResults = Location::search('Broadway')->get();
        $this->assertCount(1, $addressResults);
        $this->assertEquals('Uptown Branch', $addressResults->first()->name);

        // Search by city
        $cityResults = Location::search('Buffalo')->get();
        $this->assertCount(1, $cityResults);
        $this->assertEquals('Mall Location', $cityResults->first()->name);

        // Partial search
        $partialResults = Location::search('town')->get();
        $this->assertCount(2, $partialResults); // Downtown and Uptown
    }

    /**
     * Test city, state, and country filter scopes.
     */
    public function test_location_filter_scopes(): void
    {
        $locations = [
            Location::factory()->create([
                'organization_id' => $this->organization->id,
                'city' => 'New York',
                'state_province' => 'NY',
                'country_code' => 'US',
            ]),
            Location::factory()->create([
                'organization_id' => $this->organization->id,
                'city' => 'Los Angeles',
                'state_province' => 'CA',
                'country_code' => 'US',
            ]),
            Location::factory()->create([
                'organization_id' => $this->organization->id,
                'city' => 'Toronto',
                'state_province' => 'ON',
                'country_code' => 'CA',
            ]),
        ];

        // Test city filter
        $nycResults = Location::inCity('New York')->get();
        $this->assertCount(1, $nycResults);
        $this->assertEquals('New York', $nycResults->first()->city);

        // Test state filter
        $caResults = Location::inState('CA')->get();
        $this->assertCount(1, $caResults);
        $this->assertEquals('Los Angeles', $caResults->first()->city);

        // Test country filter
        $usResults = Location::inCountry('US')->get();
        $this->assertCount(2, $usResults);

        $caResults = Location::inCountry('CA')->get();
        $this->assertCount(1, $caResults);
        $this->assertEquals('Toronto', $caResults->first()->city);
    }

    /**
     * Test defaultFilters scope.
     */
    public function test_default_filters_scope(): void
    {
        $activeLocation = Location::factory()->active()->create([
            'organization_id' => $this->organization->id,
        ]);
        $inactiveLocation = Location::factory()->inactive()->create([
            'organization_id' => $this->organization->id,
        ]);

        $defaultResults = Location::defaultFilters()->get();

        $this->assertTrue($defaultResults->contains($activeLocation));
        $this->assertFalse($defaultResults->contains($inactiveLocation));
    }

    /**
     * Test full address attribute accessor.
     */
    public function test_full_address_attribute(): void
    {
        $location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'address_line_1' => '123 Main St',
            'address_line_2' => 'Suite 456',
            'city' => 'New York',
            'state_province' => 'NY',
            'postal_code' => '10001',
        ]);

        $expectedAddress = '123 Main St, Suite 456, New York, NY, 10001';
        $this->assertEquals($expectedAddress, $location->full_address);

        // Test without address_line_2
        $location2 = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'address_line_1' => '789 Broadway',
            'address_line_2' => null,
            'city' => 'Albany',
            'state_province' => 'NY',
            'postal_code' => '12345',
        ]);

        $expectedAddress2 = '789 Broadway, Albany, NY, 12345';
        $this->assertEquals($expectedAddress2, $location2->full_address);
    }

    /**
     * Test display name attribute accessor.
     */
    public function test_display_name_attribute(): void
    {
        $namedLocation = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Times Square Store',
            'address_line_1' => '123 Broadway',
        ]);

        $this->assertEquals('Times Square Store', $namedLocation->display_name);

        $unnamedLocation = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => '',
            'address_line_1' => '456 Main St',
        ]);

        $this->assertEquals('456 Main St', $unnamedLocation->display_name);
    }

    /**
     * Test updateSpatialPoint method.
     */
    public function test_update_spatial_point_method(): void
    {
        $location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'latitude' => 40.7128,
            'longitude' => -74.0060,
        ]);

        // Update coordinates
        $location->latitude = 40.7580;
        $location->longitude = -73.9855;
        $location->save();

        // Verify the point was updated by checking spatial query
        $nearby = Location::near(40.7580, -73.9855, 1)->get();
        $this->assertTrue($nearby->contains($location));

        $notNearby = Location::near(40.7128, -74.0060, 1)->get();
        $this->assertFalse($notNearby->contains($location));
    }

    /**
     * Test model boot method automatically updates spatial point.
     */
    public function test_model_boot_automatically_updates_spatial_point(): void
    {
        // Test on creation
        $location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'latitude' => 40.7128,
            'longitude' => -74.0060,
        ]);

        // Should be findable by spatial query immediately
        $found = Location::near(40.7128, -74.0060, 1)->get();
        $this->assertTrue($found->contains($location));

        // Test on update
        $location->update([
            'latitude' => 40.7580,
            'longitude' => -73.9855,
        ]);

        // Should be findable at new coordinates
        $foundAtNew = Location::near(40.7580, -73.9855, 1)->get();
        $this->assertTrue($foundAtNew->contains($location));

        // Should not be findable at old coordinates
        $notFoundAtOld = Location::near(40.7128, -74.0060, 1)->get();
        $this->assertFalse($notFoundAtOld->contains($location));
    }

    /**
     * Test route model binding with ID and slug.
     */
    public function test_route_model_binding(): void
    {
        $location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'slug' => 'test-location-slug',
        ]);

        // Test ID binding
        $foundById = $location->resolveRouteBinding($location->id);
        $this->assertEquals($location->id, $foundById->id);

        // Test slug binding
        $foundBySlug = $location->resolveRouteBinding('test-location-slug');
        $this->assertEquals($location->id, $foundBySlug->id);

        // Test explicit field binding
        $foundExplicit = $location->resolveRouteBinding('test-location-slug', 'slug');
        $this->assertEquals($location->id, $foundExplicit->id);

        // Test non-existent
        $notFound = $location->resolveRouteBinding('non-existent');
        $this->assertNull($notFound);
    }

    /**
     * Test coordinate validation works correctly.
     */
    public function test_coordinate_validation(): void
    {
        // Valid coordinates should work
        $validLocation = Location::factory()->withCoordinates(40.7128, -74.0060)->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertEquals(40.7128, $validLocation->latitude);
        $this->assertEquals(-74.0060, $validLocation->longitude);

        // Test boundary coordinates
        $northPole = Location::factory()->withCoordinates(90.0, 0.0)->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->assertEquals(90.0, $northPole->latitude);

        $southPole = Location::factory()->withCoordinates(-90.0, 0.0)->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->assertEquals(-90.0, $southPole->latitude);
    }

    /**
     * Test spatial point handling when coordinates are updated to valid values.
     */
    public function test_spatial_point_handling_with_coordinate_updates(): void
    {
        $location = Location::factory()->withCoordinates(40.7128, -74.0060)->create([
            'organization_id' => $this->organization->id,
        ]);

        // Should be findable by spatial query
        $nearby = Location::near(40.7128, -74.0060, 1)->get();
        $this->assertTrue($nearby->contains($location));

        // Update coordinates and verify spatial point is updated
        $location->latitude = 40.7580;
        $location->longitude = -73.9855;
        $location->updateSpatialPoint();

        // Should be findable at new coordinates
        $newNearby = Location::near(40.7580, -73.9855, 1)->get();
        $this->assertTrue($newNearby->contains($location));
    }

    /**
     * Test OSM fields are fillable and nullable.
     */
    public function test_osm_fields_are_fillable_and_nullable(): void
    {
        $osmFields = ['osm_id', 'osm_type', 'osm_data'];

        $location = new Location;

        foreach ($osmFields as $field) {
            $this->assertContains($field, $location->getFillable());
        }

        // Test creation without OSM fields (backward compatibility)
        $locationWithoutOsm = Location::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertNull($locationWithoutOsm->osm_id);
        $this->assertNull($locationWithoutOsm->osm_type);
        $this->assertNull($locationWithoutOsm->osm_data);
    }

    /**
     * Test osm_data correctly casts to/from array.
     */
    public function test_osm_data_casting_to_array(): void
    {
        $osmData = [
            'name' => 'Test Restaurant',
            'amenity' => 'restaurant',
            'addr:street' => 'Main Street',
            'addr:housenumber' => '123',
            'cuisine' => 'italian',
        ];

        $location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'osm_id' => 123456789,
            'osm_type' => 'node',
            'osm_data' => $osmData,
        ]);

        // Verify osm_data is cast to array
        $this->assertIsArray($location->osm_data);
        $this->assertEquals($osmData, $location->osm_data);

        // Verify specific fields
        $this->assertEquals('Test Restaurant', $location->osm_data['name']);
        $this->assertEquals('restaurant', $location->osm_data['amenity']);
        $this->assertEquals('Main Street', $location->osm_data['addr:street']);

        // Reload from database and verify persistence
        $location->refresh();
        $this->assertIsArray($location->osm_data);
        $this->assertEquals($osmData, $location->osm_data);
    }

    /**
     * Test location creation with OSM node type.
     */
    public function test_location_with_osm_node_type(): void
    {
        $location = Location::factory()->osmNode()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertNotNull($location->osm_id);
        $this->assertEquals('node', $location->osm_type);
        $this->assertIsArray($location->osm_data);
        $this->assertArrayHasKey('amenity', $location->osm_data);
        $this->assertArrayHasKey('name', $location->osm_data);
    }

    /**
     * Test location creation with OSM way type.
     */
    public function test_location_with_osm_way_type(): void
    {
        $location = Location::factory()->osmWay()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertNotNull($location->osm_id);
        $this->assertEquals('way', $location->osm_type);
        $this->assertIsArray($location->osm_data);
        $this->assertArrayHasKey('amenity', $location->osm_data);
    }

    /**
     * Test location creation with OSM relation type.
     */
    public function test_location_with_osm_relation_type(): void
    {
        $location = Location::factory()->osmRelation()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertNotNull($location->osm_id);
        $this->assertEquals('relation', $location->osm_type);
        $this->assertIsArray($location->osm_data);
    }

    /**
     * Test osm_type enum values are properly constrained.
     */
    public function test_osm_type_enum_values(): void
    {
        $validTypes = ['node', 'way', 'relation'];

        foreach ($validTypes as $type) {
            $location = Location::factory()->withOsmData()->create([
                'organization_id' => $this->organization->id,
                'osm_type' => $type,
            ]);

            $this->assertEquals($type, $location->osm_type);
        }
    }

    /**
     * Test osm_data JSON structure validation.
     */
    public function test_osm_data_structure_validation(): void
    {
        $location = Location::factory()->withOsmData()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertIsArray($location->osm_data);

        // OSM data should contain typical OSM tag structure
        $requiredKeys = ['name', 'amenity'];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $location->osm_data);
        }

        // Address tags should follow OSM addr:* convention
        $this->assertArrayHasKey('addr:city', $location->osm_data);
        $this->assertArrayHasKey('addr:state', $location->osm_data);
        $this->assertArrayHasKey('addr:postcode', $location->osm_data);
    }

    /**
     * Test backward compatibility - locations without OSM data work normally.
     */
    public function test_backward_compatibility_without_osm_data(): void
    {
        $location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Regular Location',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
        ]);

        // All normal operations should work
        $this->assertEquals('Regular Location', $location->name);
        $this->assertEquals(40.7128, $location->latitude);
        $this->assertNull($location->osm_id);
        $this->assertNull($location->osm_type);
        $this->assertNull($location->osm_data);

        // Should be findable by spatial queries
        $nearby = Location::near(40.7128, -74.0060, 1)->get();
        $this->assertTrue($nearby->contains($location));

        // Should work with relationships
        $this->assertInstanceOf(Organization::class, $location->organization);
    }

    /**
     * Test updating osm_data preserves structure.
     */
    public function test_updating_osm_data_preserves_structure(): void
    {
        $initialData = [
            'name' => 'Initial Name',
            'amenity' => 'cafe',
        ];

        $location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'osm_id' => 123456,
            'osm_type' => 'node',
            'osm_data' => $initialData,
        ]);

        // Update osm_data
        $updatedData = [
            'name' => 'Updated Name',
            'amenity' => 'restaurant',
            'cuisine' => 'italian',
            'opening_hours' => 'Mo-Su 09:00-22:00',
        ];

        $location->update(['osm_data' => $updatedData]);

        $this->assertEquals($updatedData, $location->osm_data);
        $this->assertEquals('Updated Name', $location->osm_data['name']);
        $this->assertEquals('italian', $location->osm_data['cuisine']);
    }
}
