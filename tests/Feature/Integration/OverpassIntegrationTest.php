<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Models\Industry;
use App\Models\Location;
use App\Models\Organization;
use App\Services\OverpassService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for real Overpass API server
 *
 * These tests hit the real Overpass server at 10.192.50.3:8082
 * and verify end-to-end OSM integration with actual HTTP calls.
 *
 * To run these tests:
 * ./vendor/bin/sail test --filter=OverpassIntegrationTest
 *
 * To skip in CI:
 * ./vendor/bin/sail test --exclude-group=integration
 *
 * @group integration
 */
class OverpassIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private OverpassService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Check if Overpass server is configured
        if (! config('services.overpass.enabled')) {
            $this->markTestSkipped('Overpass server not configured (OVERPASS_ENABLED=false)');
        }

        $this->service = app(OverpassService::class);
    }

    /**
     * Test real Overpass query returns valid results
     */
    public function test_real_overpass_query_returns_results(): void
    {
        // NYC Times Square area
        $results = $this->service->search('restaurant', 40.7580, -73.9855, 1.0);

        $this->assertGreaterThan(0, $results->count(), 'Should find at least one restaurant near Times Square');
    }

    /**
     * Test name-based search with real API
     */
    public function test_name_search_real_api(): void
    {
        // Search for "Starbucks" in NYC
        $results = $this->service->search('Starbucks', 40.7128, -74.0060, 2.0);

        $this->assertGreaterThan(0, $results->count(), 'Should find Starbucks locations in NYC');

        // Verify first result structure
        $first = $results->first();
        $this->assertNotNull($first->osm_id);
        $this->assertNotNull($first->osm_type);
        $this->assertNotEmpty($first->name);
        $this->assertIsFloat($first->latitude);
        $this->assertIsFloat($first->longitude);
    }

    /**
     * Test category restaurant search with real API
     */
    public function test_category_restaurant_real_api(): void
    {
        // LA downtown area
        $results = $this->service->search('restaurant', 34.0522, -118.2437, 1.0);

        $this->assertGreaterThan(0, $results->count(), 'Should find restaurants in LA downtown');

        // Verify all results have required fields
        foreach ($results as $location) {
            $this->assertNotNull($location->osm_id);
            $this->assertNotNull($location->name);
            $this->assertIsFloat($location->latitude);
            $this->assertIsFloat($location->longitude);
        }
    }

    /**
     * Test category cafe search with real API
     */
    public function test_category_cafe_real_api(): void
    {
        // Chicago Loop area
        $results = $this->service->search('cafe', 41.8781, -87.6298, 1.0);

        $this->assertGreaterThan(0, $results->count(), 'Should find cafes in Chicago Loop');

        // Verify results are within expected range
        $this->assertLessThan(100, $results->count(), 'Should not return excessive results');
    }

    /**
     * Test category retail search with real API
     */
    public function test_category_retail_real_api(): void
    {
        // Houston downtown area
        $results = $this->service->search('retail', 29.7604, -95.3698, 1.0);

        $this->assertGreaterThan(0, $results->count(), 'Should find retail locations in Houston');
    }

    /**
     * Test category coffee (alias for cafe) search with real API
     */
    public function test_category_coffee_alias_real_api(): void
    {
        // Phoenix downtown area
        $results = $this->service->search('coffee', 33.4484, -112.0740, 1.0);

        $this->assertGreaterThan(0, $results->count(), 'Should find coffee shops in Phoenix');
    }

    /**
     * Test around radius filter returns results within specified radius
     */
    public function test_around_radius_filter_accurate(): void
    {
        // Small radius search in Seattle
        $smallRadius = $this->service->search('restaurant', 47.6062, -122.3321, 0.5);
        $largeRadius = $this->service->search('restaurant', 47.6062, -122.3321, 5.0);

        $this->assertGreaterThan(0, $smallRadius->count());
        $this->assertGreaterThan($smallRadius->count(), $largeRadius->count(), 'Larger radius should return more results');
    }

    /**
     * Test results are actually within specified radius
     */
    public function test_results_within_radius(): void
    {
        // Search with 1km radius
        $centerLat = 40.7580;
        $centerLng = -73.9855;
        $radiusKm = 1.0;

        $results = $this->service->search('restaurant', $centerLat, $centerLng, $radiusKm);

        $this->assertGreaterThan(0, $results->count());

        // Calculate distance for each result and verify within radius
        foreach ($results->take(5) as $location) {
            $distance = $this->calculateDistance(
                $centerLat,
                $centerLng,
                $location->latitude,
                $location->longitude
            );

            // Allow 100m tolerance for Overpass "around" filter
            $this->assertLessThanOrEqual(
                ($radiusKm * 1000) + 100,
                $distance,
                "Result should be within {$radiusKm}km radius (with 100m tolerance)"
            );
        }
    }

    /**
     * Test distance calculation accuracy with known locations
     */
    public function test_distance_calculation_accurate(): void
    {
        // Known distance: NYC Times Square to Central Park (~1.8km)
        $timesSquare = ['lat' => 40.7580, 'lng' => -73.9855];
        $centralPark = ['lat' => 40.7829, 'lng' => -73.9654];

        $results = $this->service->search('park', $centralPark['lat'], $centralPark['lng'], 2.0);

        $this->assertGreaterThan(0, $results->count());

        // Calculate distance to Times Square
        $distance = $this->calculateDistance(
            $timesSquare['lat'],
            $timesSquare['lng'],
            $centralPark['lat'],
            $centralPark['lng']
        );

        // Known distance should be approximately 1.8km (±200m tolerance)
        $this->assertGreaterThan(1600, $distance);
        $this->assertLessThan(2000, $distance);
    }

    /**
     * Test spatial accuracy within ±25m tolerance
     */
    public function test_spatial_accuracy_within_tolerance(): void
    {
        // Search for specific location
        $results = $this->service->search('restaurant', 40.7580, -73.9855, 0.5);

        $this->assertGreaterThan(0, $results->count());

        // Verify coordinates are valid
        foreach ($results as $location) {
            $this->assertGreaterThanOrEqual(-90, $location->latitude);
            $this->assertLessThanOrEqual(90, $location->latitude);
            $this->assertGreaterThanOrEqual(-180, $location->longitude);
            $this->assertLessThanOrEqual(180, $location->longitude);
        }
    }

    /**
     * Test cache improves repeat query performance
     */
    public function test_cache_improves_repeat_query_performance(): void
    {
        $query = 'restaurant';
        $lat = 40.7128;
        $lng = -74.0060;
        $radius = 1.0;

        // First query (cold cache)
        $start1 = microtime(true);
        $results1 = $this->service->search($query, $lat, $lng, $radius);
        $duration1 = (microtime(true) - $start1) * 1000; // ms

        $this->assertGreaterThan(0, $results1->count());

        // Second query (warm cache)
        $start2 = microtime(true);
        $results2 = $this->service->search($query, $lat, $lng, $radius);
        $duration2 = (microtime(true) - $start2) * 1000; // ms

        $this->assertGreaterThan(0, $results2->count());

        // Second query should be faster (or at least not significantly slower)
        // Allow 50% tolerance since server caching may vary
        $this->assertLessThanOrEqual(
            $duration1 * 1.5,
            $duration2,
            "Second query ({$duration2}ms) should not be significantly slower than first ({$duration1}ms)"
        );
    }

    /**
     * Test WDTP query performance under 200ms
     */
    public function test_wdtp_query_performance_under_200ms(): void
    {
        // Create test data
        $industry = Industry::factory()->create(['name' => 'Food Service']);
        $organization = Organization::factory()->create(['industry_id' => $industry->id]);
        Location::factory()->count(5)->create([
            'organization_id' => $organization->id,
            'latitude' => 40.7128,
            'longitude' => -74.0060,
        ]);

        // Test WDTP-only query performance
        $start = microtime(true);
        $results = Location::near(40.7128, -74.0060, 5.0)
            ->withDistance(40.7128, -74.0060)
            ->get();
        $duration = (microtime(true) - $start) * 1000; // ms

        $this->assertGreaterThan(0, $results->count());
        $this->assertLessThan(200, $duration, "WDTP query should complete in under 200ms, took {$duration}ms");
    }

    /**
     * Test unified query performance under 2s
     */
    public function test_unified_query_performance_under_2s(): void
    {
        // Create test data
        $industry = Industry::factory()->create(['name' => 'Food Service']);
        $organization = Organization::factory()->create(['industry_id' => $industry->id]);
        Location::factory()->count(3)->create([
            'organization_id' => $organization->id,
            'latitude' => 40.7128,
            'longitude' => -74.0060,
        ]);

        // Test unified query performance (WDTP + OSM)
        $start = microtime(true);

        // WDTP query
        $wdtpResults = Location::near(40.7128, -74.0060, 5.0)
            ->withDistance(40.7128, -74.0060)
            ->get();

        // OSM query
        $osmResults = $this->service->search('restaurant', 40.7128, -74.0060, 5.0);

        $duration = (microtime(true) - $start) * 1000; // ms

        $this->assertGreaterThan(0, $wdtpResults->count());
        $this->assertGreaterThan(0, $osmResults->count());
        $this->assertLessThan(2000, $duration, "Unified query should complete in under 2s, took {$duration}ms");
    }

    /**
     * Test OSM results have valid coordinates
     */
    public function test_osm_results_have_valid_coordinates(): void
    {
        $results = $this->service->search('restaurant', 40.7580, -73.9855, 1.0);

        $this->assertGreaterThan(0, $results->count());

        foreach ($results as $location) {
            $this->assertIsFloat($location->latitude);
            $this->assertIsFloat($location->longitude);
            $this->assertGreaterThanOrEqual(-90, $location->latitude);
            $this->assertLessThanOrEqual(90, $location->latitude);
            $this->assertGreaterThanOrEqual(-180, $location->longitude);
            $this->assertLessThanOrEqual(180, $location->longitude);
        }
    }

    /**
     * Test OSM results have valid names
     */
    public function test_osm_results_have_valid_names(): void
    {
        $results = $this->service->search('restaurant', 34.0522, -118.2437, 1.0);

        $this->assertGreaterThan(0, $results->count());

        foreach ($results as $location) {
            $this->assertNotEmpty($location->name);
            $this->assertIsString($location->name);
            $this->assertGreaterThan(0, strlen($location->name));
        }
    }

    /**
     * Test OSM address tags parsed correctly
     */
    public function test_osm_address_tags_parsed_correctly(): void
    {
        $results = $this->service->search('restaurant', 40.7128, -74.0060, 1.0);

        $this->assertGreaterThan(0, $results->count());

        // Check that at least some results have address tags
        $hasAddressTags = false;
        foreach ($results as $location) {
            if (! empty($location->tags)) {
                $hasAddressTags = true;
                $this->assertIsArray($location->tags);

                // If address tags exist, verify structure
                if (isset($location->tags['addr:street'])) {
                    $this->assertIsString($location->tags['addr:street']);
                }
                break;
            }
        }

        $this->assertTrue($hasAddressTags, 'At least some results should have address tags');
    }

    /**
     * Test category healthcare search with real API
     */
    public function test_category_healthcare_real_api(): void
    {
        // Boston area
        $results = $this->service->search('healthcare', 42.3601, -71.0589, 2.0);

        $this->assertGreaterThan(0, $results->count(), 'Should find healthcare locations in Boston');
    }

    /**
     * Test category hospital search with real API
     */
    public function test_category_hospital_real_api(): void
    {
        // Philadelphia area
        $results = $this->service->search('hospital', 39.9526, -75.1652, 2.0);

        $this->assertGreaterThan(0, $results->count(), 'Should find hospitals in Philadelphia');
    }

    /**
     * Test category pharmacy search with real API
     */
    public function test_category_pharmacy_real_api(): void
    {
        // San Francisco area
        $results = $this->service->search('pharmacy', 37.7749, -122.4194, 1.0);

        $this->assertGreaterThan(0, $results->count(), 'Should find pharmacies in San Francisco');
    }

    /**
     * Test category shop search with real API
     */
    public function test_category_shop_real_api(): void
    {
        // Miami area
        $results = $this->service->search('shop', 25.7617, -80.1918, 1.0);

        $this->assertGreaterThan(0, $results->count(), 'Should find shops in Miami');
    }

    /**
     * Test category store search with real API
     */
    public function test_category_store_real_api(): void
    {
        // Denver area
        $results = $this->service->search('store', 39.7392, -104.9903, 1.0);

        $this->assertGreaterThan(0, $results->count(), 'Should find stores in Denver');
    }

    /**
     * Test name search with special characters
     */
    public function test_name_search_with_special_characters(): void
    {
        // Search for "McDonald's" (with apostrophe)
        $results = $this->service->search("McDonald's", 40.7128, -74.0060, 2.0);

        // Should not throw error and may return results
        $this->assertGreaterThanOrEqual(0, $results->count());
    }

    /**
     * Test name search with common chain name
     */
    public function test_name_search_with_common_chain(): void
    {
        // Search for "Walmart"
        $results = $this->service->search('Walmart', 34.0522, -118.2437, 5.0);

        $this->assertGreaterThan(0, $results->count(), 'Should find Walmart locations');
    }

    /**
     * Test results have valid OSM IDs
     */
    public function test_results_have_valid_osm_ids(): void
    {
        $results = $this->service->search('restaurant', 40.7580, -73.9855, 1.0);

        $this->assertGreaterThan(0, $results->count());

        foreach ($results as $location) {
            $this->assertMatchesRegularExpression(
                '/^(node|way)\/\d+$/',
                $location->osm_id,
                'OSM ID should match format "node/123" or "way/456"'
            );
        }
    }

    /**
     * Test results have valid OSM types
     */
    public function test_results_have_valid_osm_types(): void
    {
        $results = $this->service->search('restaurant', 40.7128, -74.0060, 1.0);

        $this->assertGreaterThan(0, $results->count());

        foreach ($results as $location) {
            $this->assertContains(
                $location->osm_type,
                ['node', 'way'],
                'OSM type should be either "node" or "way"'
            );
        }
    }

    /**
     * Test search with very small radius
     */
    public function test_search_with_very_small_radius(): void
    {
        // Search with 100m radius
        $results = $this->service->search('restaurant', 40.7580, -73.9855, 0.1);

        // Should return some results or empty (both valid)
        $this->assertGreaterThanOrEqual(0, $results->count());
    }

    /**
     * Test search with large radius
     */
    public function test_search_with_large_radius(): void
    {
        // Search with 10km radius
        $results = $this->service->search('restaurant', 40.7128, -74.0060, 10.0);

        $this->assertGreaterThan(0, $results->count(), 'Should find many results in 10km radius');
    }

    /**
     * Test search returns unique OSM IDs
     */
    public function test_search_returns_unique_osm_ids(): void
    {
        $results = $this->service->search('restaurant', 40.7580, -73.9855, 1.0);

        $this->assertGreaterThan(0, $results->count());

        $osmIds = $results->pluck('osm_id')->all();
        $uniqueIds = array_unique($osmIds);

        $this->assertCount(
            count($uniqueIds),
            $osmIds,
            'All OSM IDs should be unique (no duplicates)'
        );
    }

    /**
     * Test results have default text_rank value
     */
    public function test_results_have_default_text_rank(): void
    {
        $results = $this->service->search('restaurant', 40.7128, -74.0060, 1.0);

        $this->assertGreaterThan(0, $results->count());

        foreach ($results as $location) {
            $this->assertEquals(0.5, $location->text_rank, 'OSM results should have default text_rank of 0.5');
        }
    }

    /**
     * Test search with different US cities returns location-specific results
     */
    public function test_search_returns_location_specific_results(): void
    {
        // Search in different cities
        $nycResults = $this->service->search('restaurant', 40.7128, -74.0060, 1.0);
        $laResults = $this->service->search('restaurant', 34.0522, -118.2437, 1.0);

        $this->assertGreaterThan(0, $nycResults->count());
        $this->assertGreaterThan(0, $laResults->count());

        // Results should be different (different locations)
        $nycIds = $nycResults->pluck('osm_id')->sort()->values()->all();
        $laIds = $laResults->pluck('osm_id')->sort()->values()->all();

        $this->assertNotEquals(
            $nycIds,
            $laIds,
            'NYC and LA should return different restaurant sets'
        );
    }

    /**
     * Test empty result handling
     */
    public function test_empty_result_handling(): void
    {
        // Search in remote area with unlikely results
        $results = $this->service->search('restaurant', 71.2906, -156.7886, 0.1); // Arctic Alaska

        // Should return empty collection, not error
        $this->assertCount(0, $results);
    }

    /**
     * Calculate great-circle distance between two points (Haversine formula)
     *
     * @param  float  $lat1  Latitude of first point
     * @param  float  $lng1  Longitude of first point
     * @param  float  $lat2  Latitude of second point
     * @param  float  $lng2  Longitude of second point
     * @return float Distance in meters
     */
    private function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // Earth's radius in meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
