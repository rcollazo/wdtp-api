<?php

namespace Tests\Feature;

use App\Models\Industry;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UnifiedLocationSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $industry = Industry::factory()->create(['name' => 'Food Service']);
        $organization = Organization::factory()->create([
            'name' => 'Pizza Palace',
            'industry_id' => $industry->id,
        ]);

        // Create locations in NYC area
        Location::factory()->create([
            'name' => 'Pizza Palace Downtown',
            'organization_id' => $organization->id,
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'address_line_1' => '123 Main St',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
            'country' => 'US',
            'status' => 'active',
        ]);

        Location::factory()->create([
            'name' => 'Pizza Palace Midtown',
            'organization_id' => $organization->id,
            'latitude' => 40.7580,
            'longitude' => -73.9855,
            'address_line_1' => '456 Broadway',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10036',
            'country' => 'US',
            'status' => 'active',
        ]);
    }

    /**
     * Test WDTP-only search (include_osm=false or not provided).
     */
    public function test_wdtp_only_search_returns_database_locations(): void
    {
        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'pizza',
            'lat' => 40.7128,
            'lng' => -74.0060,
            'radius_km' => 10,
        ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'source',
                        'location_id',
                        'name',
                        'latitude',
                        'longitude',
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
                    'osm_unavailable',
                ],
            ]);

        $data = $response->json();

        // Verify WDTP results returned
        $this->assertGreaterThan(0, $data['meta']['wdtp_count']);
        $this->assertEquals(0, $data['meta']['osm_count']);
        $this->assertFalse($data['meta']['osm_unavailable']);

        // Verify all results are WDTP sources
        foreach ($data['data'] as $location) {
            $this->assertEquals('wdtp', $location['source']);
            $this->assertNotNull($location['location_id']);
        }
    }

    /**
     * Test unified search with OSM integration (include_osm=true).
     */
    public function test_unified_search_with_osm_returns_merged_results(): void
    {
        // Mock Overpass API response
        Http::fake([
            '*' => Http::response([
                'elements' => [
                    [
                        'type' => 'node',
                        'id' => 123456,
                        'lat' => 40.7200,
                        'lon' => -74.0100,
                        'tags' => [
                            'name' => 'Joe\'s Pizza',
                            'amenity' => 'restaurant',
                            'cuisine' => 'pizza',
                            'addr:street' => 'Main Street',
                            'addr:city' => 'New York',
                        ],
                    ],
                    [
                        'type' => 'way',
                        'id' => 789012,
                        'center' => [
                            'lat' => 40.7300,
                            'lon' => -74.0200,
                        ],
                        'tags' => [
                            'name' => 'Tony\'s Pizza',
                            'amenity' => 'restaurant',
                            'addr:street' => 'Broadway',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'pizza',
            'lat' => 40.7128,
            'lng' => -74.0060,
            'radius_km' => 10,
            'include_osm' => true,
        ]));

        $response->assertStatus(200);
        $data = $response->json();

        // Verify both WDTP and OSM results returned
        $this->assertGreaterThan(0, $data['meta']['wdtp_count']);
        $this->assertGreaterThan(0, $data['meta']['osm_count']);
        $this->assertFalse($data['meta']['osm_unavailable']);

        // Verify total equals sum
        $this->assertEquals(
            $data['meta']['wdtp_count'] + $data['meta']['osm_count'],
            $data['meta']['total']
        );

        // Verify mixed sources in results
        $sources = collect($data['data'])->pluck('source')->unique();
        $this->assertContains('wdtp', $sources->toArray());
        $this->assertContains('osm', $sources->toArray());

        // Verify OSM entries include raw tags from Overpass
        $osmEntries = collect($data['data'])->where('source', 'osm');
        $this->assertNotEmpty($osmEntries);

        $expectedTags = [
            'node/123456' => [
                'name' => "Joe's Pizza",
                'amenity' => 'restaurant',
                'cuisine' => 'pizza',
                'addr:street' => 'Main Street',
                'addr:city' => 'New York',
            ],
            'way/789012' => [
                'name' => "Tony's Pizza",
                'amenity' => 'restaurant',
                'addr:street' => 'Broadway',
            ],
        ];

        $osmEntries->each(function (array $entry) use ($expectedTags): void {
            $this->assertArrayHasKey('tags', $entry);
            $this->assertArrayHasKey($entry['osm_id'], $expectedTags);
            $this->assertEquals($expectedTags[$entry['osm_id']], $entry['tags']);
        });
    }

    /**
     * Test that results are sorted by relevance_score descending.
     */
    public function test_results_sorted_by_relevance_score(): void
    {
        Http::fake([
            '*' => Http::response([
                'elements' => [
                    [
                        'type' => 'node',
                        'id' => 123456,
                        'lat' => 40.7200,
                        'lon' => -74.0100,
                        'tags' => ['name' => 'OSM Pizza'],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'pizza',
            'lat' => 40.7128,
            'lng' => -74.0060,
            'radius_km' => 10,
            'include_osm' => true,
        ]));

        $data = $response->json('data');

        // Verify scores are in descending order
        $scores = collect($data)->pluck('relevance_score')->toArray();
        $sortedScores = collect($scores)->sortDesc()->values()->toArray();

        $this->assertEquals($sortedScores, $scores);
    }

    /**
     * Test graceful degradation when OSM service fails.
     */
    public function test_osm_failure_returns_wdtp_only_with_flag(): void
    {
        // Mock OSM timeout
        Http::fake([
            '*' => Http::response([], 503),
        ]);

        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'pizza',
            'lat' => 40.7128,
            'lng' => -74.0060,
            'radius_km' => 10,
            'include_osm' => true,
        ]));

        // Should still return 200 OK with WDTP results
        $response->assertStatus(200);
        $data = $response->json();

        // Verify WDTP results returned
        $this->assertGreaterThan(0, $data['meta']['wdtp_count']);
        $this->assertEquals(0, $data['meta']['osm_count']);

        // Verify osm_unavailable flag set
        $this->assertTrue($data['meta']['osm_unavailable']);
    }

    /**
     * Test OSM connection error returns WDTP-only.
     */
    public function test_osm_connection_error_graceful_degradation(): void
    {
        // Mock connection error
        Http::fake(fn () => throw new \Exception('Connection timeout'));

        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'pizza',
            'lat' => 40.7128,
            'lng' => -74.0060,
            'radius_km' => 10,
            'include_osm' => true,
        ]));

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertGreaterThan(0, $data['meta']['wdtp_count']);
        $this->assertEquals(0, $data['meta']['osm_count']);
        $this->assertTrue($data['meta']['osm_unavailable']);
    }

    /**
     * Test pagination works correctly with merged results.
     */
    public function test_pagination_works_with_merged_results(): void
    {
        Http::fake([
            '*' => Http::response([
                'elements' => [
                    [
                        'type' => 'node',
                        'id' => 1,
                        'lat' => 40.7200,
                        'lon' => -74.0100,
                        'tags' => ['name' => 'OSM Pizza 1'],
                    ],
                    [
                        'type' => 'node',
                        'id' => 2,
                        'lat' => 40.7300,
                        'lon' => -74.0200,
                        'tags' => ['name' => 'OSM Pizza 2'],
                    ],
                ],
            ], 200),
        ]);

        // Request page 1 with 2 results per page
        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'pizza',
            'lat' => 40.7128,
            'lng' => -74.0060,
            'radius_km' => 10,
            'include_osm' => true,
            'per_page' => 2,
            'page' => 1,
        ]));

        $response->assertStatus(200);
        $data = $response->json();

        // Should have exactly 2 results on page 1
        $this->assertCount(2, $data['data']);

        // Total should be greater than per_page
        $this->assertGreaterThanOrEqual(2, $data['meta']['total']);
    }

    /**
     * Test meta object contains all required fields.
     */
    public function test_meta_object_contains_all_required_fields(): void
    {
        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'pizza',
            'lat' => 40.7128,
            'lng' => -74.0060,
            'radius_km' => 5,
        ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'meta' => [
                    'total',
                    'wdtp_count',
                    'osm_count',
                    'search_query',
                    'search_type',
                    'center' => ['lat', 'lng'],
                    'radius_km',
                    'osm_unavailable',
                ],
            ]);

        $meta = $response->json('meta');

        $this->assertEquals('pizza', $meta['search_query']);
        $this->assertEquals(40.7128, $meta['center']['lat']);
        $this->assertEquals(-74.0060, $meta['center']['lng']);
        $this->assertEquals(5, $meta['radius_km']);
        $this->assertIsBool($meta['osm_unavailable']);
    }

    /**
     * Test OSM results have correct structure.
     */
    public function test_osm_results_have_correct_structure(): void
    {
        Http::fake([
            '*' => Http::response([
                'elements' => [
                    [
                        'type' => 'node',
                        'id' => 123456,
                        'lat' => 40.7200,
                        'lon' => -74.0100,
                        'tags' => [
                            'name' => 'Test Pizza',
                            'addr:street' => 'Main St',
                            'addr:city' => 'NYC',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'pizza',
            'lat' => 40.7128,
            'lng' => -74.0060,
            'radius_km' => 10,
            'include_osm' => true,
        ]));

        $data = $response->json();

        // Find an OSM result
        $osmResult = collect($data['data'])->firstWhere('source', 'osm');

        $this->assertNotNull($osmResult);
        $this->assertEquals('osm', $osmResult['source']);
        $this->assertNotNull($osmResult['osm_id']);
        $this->assertNotNull($osmResult['osm_type']);
        $this->assertNull($osmResult['location_id']);
        $this->assertNull($osmResult['organization']);
        $this->assertFalse($osmResult['has_wage_data']);
        $this->assertEquals(0, $osmResult['wage_reports_count']);
        $this->assertEquals([
            'name' => 'Test Pizza',
            'addr:street' => 'Main St',
            'addr:city' => 'NYC',
        ], $osmResult['tags']);
    }

    /**
     * Test search type detection (name vs category).
     */
    public function test_search_type_detection(): void
    {
        // Name search
        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'palace',
            'lat' => 40.7128,
            'lng' => -74.0060,
        ]));

        $this->assertEquals('name', $response->json('meta.search_type'));

        // Category search
        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'restaurant',
            'lat' => 40.7128,
            'lng' => -74.0060,
        ]));

        $this->assertEquals('category', $response->json('meta.search_type'));
    }

    /**
     * Test that distance_meters and relevance_score are present.
     */
    public function test_results_include_distance_and_relevance(): void
    {
        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'pizza',
            'lat' => 40.7128,
            'lng' => -74.0060,
            'radius_km' => 10,
        ]));

        $data = $response->json('data');

        foreach ($data as $location) {
            $this->assertArrayHasKey('distance_meters', $location);
            $this->assertArrayHasKey('relevance_score', $location);
            $this->assertIsNumeric($location['distance_meters']);
            $this->assertIsNumeric($location['relevance_score']);
        }
    }

    /**
     * Test that OSM POI data is integrated correctly with proper tags.
     */
    public function test_osm_poi_data_integrated_correctly(): void
    {
        Http::fake([
            '*' => Http::response([
                'elements' => [
                    [
                        'type' => 'node',
                        'id' => 999999,
                        'lat' => 40.7200,
                        'lon' => -74.0100,
                        'tags' => [
                            'name' => 'Amazing Pizza Shop',
                            'amenity' => 'restaurant',
                            'cuisine' => 'pizza',
                            'addr:housenumber' => '789',
                            'addr:street' => 'Pizza Boulevard',
                            'addr:city' => 'New York',
                            'addr:postcode' => '10002',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'pizza',
            'lat' => 40.7128,
            'lng' => -74.0060,
            'radius_km' => 10,
            'include_osm' => true,
        ]));

        $data = $response->json();
        $osmResult = collect($data['data'])->firstWhere('source', 'osm');

        $this->assertNotNull($osmResult);
        $this->assertEquals('Amazing Pizza Shop', $osmResult['name']);
        $this->assertStringContainsString('Pizza Boulevard', $osmResult['address']);
        $this->assertEquals([
            'name' => 'Amazing Pizza Shop',
            'amenity' => 'restaurant',
            'cuisine' => 'pizza',
            'addr:housenumber' => '789',
            'addr:street' => 'Pizza Boulevard',
            'addr:city' => 'New York',
            'addr:postcode' => '10002',
        ], $osmResult['tags']);
    }

    /**
     * Test performance target for unified queries (<2s).
     */
    public function test_unified_query_performance(): void
    {
        Http::fake([
            '*' => Http::response([
                'elements' => [
                    [
                        'type' => 'node',
                        'id' => 123,
                        'lat' => 40.7200,
                        'lon' => -74.0100,
                        'tags' => ['name' => 'Test Pizza'],
                    ],
                ],
            ], 200),
        ]);

        $startTime = microtime(true);

        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'pizza',
            'lat' => 40.7128,
            'lng' => -74.0060,
            'radius_km' => 10,
            'include_osm' => true,
        ]));

        $endTime = microtime(true);
        $duration = $endTime - $startTime;

        $response->assertStatus(200);

        // Performance target: <2s for unified queries
        $this->assertLessThan(2.0, $duration, 'Unified query took longer than 2 seconds');
    }
}
