<?php

namespace Tests\Unit\Resources;

use App\DataTransferObjects\OsmLocation;
use App\Http\Resources\UnifiedLocationResource;
use App\Models\Industry;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiedLocationResourceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_transforms_wdtp_location_with_organization(): void
    {
        $industry = Industry::factory()->create();
        $organization = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);
        $location = Location::factory()->active()->verified()->create([
            'organization_id' => $organization->id,
            'name' => 'McDonald\'s Times Square',
        ]);
        $location->load('organization');

        $resource = new UnifiedLocationResource($location);
        $array = $resource->toArray(request());

        $this->assertEquals('wdtp', $array['source']);
        $this->assertEquals($location->id, $array['location_id']);
        $this->assertNull($array['osm_id']);
        $this->assertNull($array['osm_type']);
        $this->assertEquals('McDonald\'s Times Square', $array['name']);
        $this->assertEquals($location->latitude, $array['latitude']);
        $this->assertEquals($location->longitude, $array['longitude']);
        $this->assertNotNull($array['organization']);
        $this->assertEquals($organization->name, $array['organization']->name);
    }

    /** @test */
    public function it_transforms_wdtp_location_without_organization(): void
    {
        $location = Location::factory()->active()->verified()->create([
            'name' => 'Independent Coffee Shop',
        ]);
        // Don't load organization relationship

        $resource = new UnifiedLocationResource($location);
        $array = $resource->toArray(request());

        $this->assertEquals('wdtp', $array['source']);
        $this->assertEquals($location->id, $array['location_id']);
        $this->assertNull($array['osm_id']);
        $this->assertNull($array['osm_type']);
        $this->assertEquals('Independent Coffee Shop', $array['name']);
        $this->assertNull($array['organization']);
    }

    /** @test */
    public function it_transforms_osm_location_with_full_address_tags(): void
    {
        $osmLocation = new OsmLocation(
            osm_id: 'node/123456',
            osm_type: 'node',
            name: 'Starbucks Coffee',
            latitude: 40.7580,
            longitude: -73.9855,
            tags: [
                'addr:housenumber' => '1234',
                'addr:street' => 'Broadway',
                'addr:city' => 'New York',
                'addr:state' => 'NY',
                'addr:postcode' => '10001',
                'amenity' => 'cafe',
            ]
        );

        $resource = new UnifiedLocationResource($osmLocation);
        $array = $resource->toArray(request());

        $this->assertEquals('osm', $array['source']);
        $this->assertNull($array['location_id']);
        $this->assertEquals('node/123456', $array['osm_id']);
        $this->assertEquals('node', $array['osm_type']);
        $this->assertEquals('Starbucks Coffee', $array['name']);
        $this->assertEquals(40.7580, $array['latitude']);
        $this->assertEquals(-73.9855, $array['longitude']);
        $this->assertStringContainsString('Broadway', $array['address']);
        $this->assertStringContainsString('New York', $array['address']);
    }

    /** @test */
    public function it_transforms_osm_location_with_partial_address_tags(): void
    {
        $osmLocation = new OsmLocation(
            osm_id: 'way/789012',
            osm_type: 'way',
            name: 'Central Park Cafe',
            latitude: 40.7829,
            longitude: -73.9654,
            tags: [
                'addr:city' => 'New York',
                'amenity' => 'cafe',
            ]
        );

        $resource = new UnifiedLocationResource($osmLocation);
        $array = $resource->toArray(request());

        $this->assertEquals('osm', $array['source']);
        $this->assertEquals('way/789012', $array['osm_id']);
        $this->assertEquals('way', $array['osm_type']);
        $this->assertStringContainsString('New York', $array['address']);
    }

    /** @test */
    public function it_sets_source_field_correctly_for_wdtp(): void
    {
        $location = Location::factory()->create();

        $resource = new UnifiedLocationResource($location);
        $array = $resource->toArray(request());

        $this->assertEquals('wdtp', $array['source']);
    }

    /** @test */
    public function it_sets_source_field_correctly_for_osm(): void
    {
        $osmLocation = new OsmLocation(
            osm_id: 'node/999',
            osm_type: 'node',
            name: 'Test Location',
            latitude: 40.0,
            longitude: -74.0,
            tags: []
        );

        $resource = new UnifiedLocationResource($osmLocation);
        $array = $resource->toArray(request());

        $this->assertEquals('osm', $array['source']);
    }

    /** @test */
    public function it_sets_location_id_for_wdtp_and_null_for_osm(): void
    {
        $location = Location::factory()->create();
        $osmLocation = new OsmLocation(
            osm_id: 'node/123',
            osm_type: 'node',
            name: 'OSM Test',
            latitude: 40.0,
            longitude: -74.0,
            tags: []
        );

        $wdtpResource = new UnifiedLocationResource($location);
        $osmResource = new UnifiedLocationResource($osmLocation);

        $wdtpArray = $wdtpResource->toArray(request());
        $osmArray = $osmResource->toArray(request());

        $this->assertNotNull($wdtpArray['location_id']);
        $this->assertEquals($location->id, $wdtpArray['location_id']);
        $this->assertNull($osmArray['location_id']);
    }

    /** @test */
    public function it_sets_osm_id_and_type_for_osm_and_null_for_wdtp(): void
    {
        $location = Location::factory()->create();
        $osmLocation = new OsmLocation(
            osm_id: 'way/456',
            osm_type: 'way',
            name: 'OSM Test',
            latitude: 40.0,
            longitude: -74.0,
            tags: []
        );

        $wdtpResource = new UnifiedLocationResource($location);
        $osmResource = new UnifiedLocationResource($osmLocation);

        $wdtpArray = $wdtpResource->toArray(request());
        $osmArray = $osmResource->toArray(request());

        $this->assertNull($wdtpArray['osm_id']);
        $this->assertNull($wdtpArray['osm_type']);
        $this->assertEquals('way/456', $osmArray['osm_id']);
        $this->assertEquals('way', $osmArray['osm_type']);
    }

    /** @test */
    public function it_sets_has_wage_data_true_for_wdtp_with_wage_reports(): void
    {
        $location = Location::factory()->create();
        // Simulate wage reports relationship loaded with count > 0
        $location->setRelation('wageReports', collect(['report1', 'report2']));

        $resource = new UnifiedLocationResource($location);
        $array = $resource->toArray(request());

        $this->assertTrue($array['has_wage_data']);
        $this->assertEquals(2, $array['wage_reports_count']);
    }

    /** @test */
    public function it_sets_has_wage_data_false_for_wdtp_without_wage_reports(): void
    {
        $location = Location::factory()->create();
        // Simulate wage reports relationship loaded with count = 0
        $location->setRelation('wageReports', collect([]));

        $resource = new UnifiedLocationResource($location);
        $array = $resource->toArray(request());

        $this->assertFalse($array['has_wage_data']);
        $this->assertEquals(0, $array['wage_reports_count']);
    }

    /** @test */
    public function it_sets_has_wage_data_false_for_osm_locations(): void
    {
        $osmLocation = new OsmLocation(
            osm_id: 'node/123',
            osm_type: 'node',
            name: 'OSM Test',
            latitude: 40.0,
            longitude: -74.0,
            tags: []
        );

        $resource = new UnifiedLocationResource($osmLocation);
        $array = $resource->toArray(request());

        $this->assertFalse($array['has_wage_data']);
        $this->assertEquals(0, $array['wage_reports_count']);
    }

    /** @test */
    public function it_handles_null_safety_for_optional_fields(): void
    {
        $location = Location::factory()->create();
        // Don't load relationships or set optional fields

        $resource = new UnifiedLocationResource($location);
        $array = $resource->toArray(request());

        // Should not throw exceptions on null checks
        $this->assertIsArray($array);
        $this->assertArrayHasKey('organization', $array);
        $this->assertArrayHasKey('has_wage_data', $array);
    }

    /** @test */
    public function it_includes_distance_meters_when_present(): void
    {
        $location = Location::factory()->create();
        $location->distance_meters = 1234.56;

        $resource = new UnifiedLocationResource($location);
        $array = $resource->toArray(request());

        $this->assertArrayHasKey('distance_meters', $array);
        $this->assertEquals(1235, $array['distance_meters']); // Rounded
    }

    /** @test */
    public function it_includes_relevance_score_when_present(): void
    {
        $location = Location::factory()->create();
        $location->relevance_score = 0.8567;

        $resource = new UnifiedLocationResource($location);
        $array = $resource->toArray(request());

        $this->assertArrayHasKey('relevance_score', $array);
        $this->assertEquals(0.86, $array['relevance_score']); // Rounded to 2 decimals
    }

    /** @test */
    public function it_uses_full_address_for_wdtp_locations(): void
    {
        $location = Location::factory()->create([
            'address_line_1' => '123 Main St',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
        ]);

        $resource = new UnifiedLocationResource($location);
        $array = $resource->toArray(request());

        $this->assertStringContainsString('123 Main St', $array['address']);
        $this->assertStringContainsString('New York', $array['address']);
    }

    /** @test */
    public function it_uses_format_address_for_osm_locations(): void
    {
        $osmLocation = new OsmLocation(
            osm_id: 'node/123',
            osm_type: 'node',
            name: 'Test',
            latitude: 40.0,
            longitude: -74.0,
            tags: [
                'addr:street' => 'Broadway',
                'addr:city' => 'New York',
            ]
        );

        $resource = new UnifiedLocationResource($osmLocation);
        $array = $resource->toArray(request());

        $address = $osmLocation->formatAddress();
        $this->assertEquals($address, $array['address']);
    }
}
