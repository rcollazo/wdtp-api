<?php

declare(strict_types=1);

namespace Tests\Unit\DataTransferObjects;

use App\DataTransferObjects\OsmLocation;
use Tests\TestCase;

class OsmLocationTest extends TestCase
{
    public function test_create_with_full_tags(): void
    {
        $tags = [
            'name' => 'Test Location',
            'addr:housenumber' => '123',
            'addr:street' => 'Main Street',
            'addr:city' => 'New York',
            'addr:state' => 'NY',
            'amenity' => 'restaurant',
        ];

        $location = new OsmLocation(
            osm_id: 'node/123456',
            osm_type: 'node',
            name: 'Test Location',
            latitude: 40.7128,
            longitude: -74.0060,
            tags: $tags,
            distance_meters: 500.5,
            relevance_score: 0.95
        );

        $this->assertSame('node/123456', $location->osm_id);
        $this->assertSame('node', $location->osm_type);
        $this->assertSame('Test Location', $location->name);
        $this->assertSame(40.7128, $location->latitude);
        $this->assertSame(-74.0060, $location->longitude);
        $this->assertSame($tags, $location->tags);
        $this->assertSame(500.5, $location->distance_meters);
        $this->assertSame(0.95, $location->relevance_score);
        $this->assertSame(0.5, $location->text_rank);
    }

    public function test_create_with_minimal_tags(): void
    {
        $tags = [
            'name' => 'Minimal Location',
        ];

        $location = new OsmLocation(
            osm_id: 'way/789012',
            osm_type: 'way',
            name: 'Minimal Location',
            latitude: 34.0522,
            longitude: -118.2437,
            tags: $tags
        );

        $this->assertSame('way/789012', $location->osm_id);
        $this->assertSame('way', $location->osm_type);
        $this->assertSame('Minimal Location', $location->name);
        $this->assertSame(34.0522, $location->latitude);
        $this->assertSame(-118.2437, $location->longitude);
        $this->assertSame($tags, $location->tags);
        $this->assertNull($location->distance_meters);
        $this->assertNull($location->relevance_score);
        $this->assertSame(0.5, $location->text_rank);
    }

    public function test_format_address_with_full_components(): void
    {
        $tags = [
            'addr:housenumber' => '123',
            'addr:street' => 'Main Street',
            'addr:city' => 'New York',
            'addr:state' => 'NY',
        ];

        $location = new OsmLocation(
            osm_id: 'node/123456',
            osm_type: 'node',
            name: 'Test Location',
            latitude: 40.7128,
            longitude: -74.0060,
            tags: $tags
        );

        $address = $location->formatAddress();

        $this->assertSame('123, Main Street, New York, NY', $address);
    }

    public function test_format_address_with_partial_components(): void
    {
        $tags = [
            'addr:city' => 'Los Angeles',
            'addr:state' => 'CA',
        ];

        $location = new OsmLocation(
            osm_id: 'node/999999',
            osm_type: 'node',
            name: 'Partial Address',
            latitude: 34.0522,
            longitude: -118.2437,
            tags: $tags
        );

        $address = $location->formatAddress();

        $this->assertSame('Los Angeles, CA', $address);
    }

    public function test_format_address_with_street_only(): void
    {
        $tags = [
            'addr:street' => 'Broadway',
        ];

        $location = new OsmLocation(
            osm_id: 'node/555555',
            osm_type: 'node',
            name: 'Street Only',
            latitude: 40.7580,
            longitude: -73.9855,
            tags: $tags
        );

        $address = $location->formatAddress();

        $this->assertSame('Broadway', $address);
    }

    public function test_format_address_with_no_components_returns_null(): void
    {
        $tags = [
            'name' => 'No Address',
            'amenity' => 'cafe',
        ];

        $location = new OsmLocation(
            osm_id: 'node/777777',
            osm_type: 'node',
            name: 'No Address',
            latitude: 41.8781,
            longitude: -87.6298,
            tags: $tags
        );

        $address = $location->formatAddress();

        $this->assertNull($address);
    }

    public function test_format_address_filters_empty_components(): void
    {
        $tags = [
            'addr:housenumber' => '',
            'addr:street' => 'Main Street',
            'addr:city' => '',
            'addr:state' => 'TX',
        ];

        $location = new OsmLocation(
            osm_id: 'node/888888',
            osm_type: 'node',
            name: 'Empty Components',
            latitude: 29.7604,
            longitude: -95.3698,
            tags: $tags
        );

        $address = $location->formatAddress();

        $this->assertSame('Main Street, TX', $address);
    }

    public function test_default_text_rank_is_point_five(): void
    {
        $location = new OsmLocation(
            osm_id: 'node/111111',
            osm_type: 'node',
            name: 'Default Rank',
            latitude: 33.4484,
            longitude: -112.0740,
            tags: []
        );

        $this->assertSame(0.5, $location->text_rank);
    }

    public function test_custom_text_rank_overrides_default(): void
    {
        $location = new OsmLocation(
            osm_id: 'node/222222',
            osm_type: 'node',
            name: 'Custom Rank',
            latitude: 39.7392,
            longitude: -104.9903,
            tags: [],
            text_rank: 0.85
        );

        $this->assertSame(0.85, $location->text_rank);
    }

    public function test_all_required_properties_populated(): void
    {
        $location = new OsmLocation(
            osm_id: 'node/333333',
            osm_type: 'node',
            name: 'Complete Properties',
            latitude: 37.7749,
            longitude: -122.4194,
            tags: ['amenity' => 'restaurant'],
            distance_meters: 1500.75,
            relevance_score: 0.82,
            text_rank: 0.65
        );

        // Verify all required properties are set
        $this->assertIsString($location->osm_id);
        $this->assertIsString($location->osm_type);
        $this->assertIsString($location->name);
        $this->assertIsFloat($location->latitude);
        $this->assertIsFloat($location->longitude);
        $this->assertIsArray($location->tags);
        $this->assertIsFloat($location->distance_meters);
        $this->assertIsFloat($location->relevance_score);
        $this->assertIsFloat($location->text_rank);

        // Verify values
        $this->assertNotEmpty($location->osm_id);
        $this->assertNotEmpty($location->osm_type);
        $this->assertNotEmpty($location->name);
    }

    public function test_readonly_properties_cannot_be_modified(): void
    {
        $location = new OsmLocation(
            osm_id: 'node/444444',
            osm_type: 'node',
            name: 'Readonly Test',
            latitude: 42.3601,
            longitude: -71.0589,
            tags: []
        );

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');

        // Attempt to modify readonly property (should throw Error)
        $location->name = 'Modified Name';
    }
}
