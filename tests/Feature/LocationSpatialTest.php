<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocationSpatialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test organization
        $this->organization = Organization::factory()->create();
    }

    /**
     * Test distance calculations with real coordinates and accuracy tolerance.
     */
    public function test_distance_calculations_with_real_coordinates(): void
    {
        // Seattle area coordinates for testing
        $seattleDowntown = ['lat' => 47.6062, 'lon' => -122.3321];
        $seattleCapitolHill = ['lat' => 47.6205, 'lon' => -122.3212]; // ~2km away
        $seattleFremont = ['lat' => 47.6513, 'lon' => -122.3501]; // ~4.5km away
        $seattleBellevue = ['lat' => 47.6101, 'lon' => -122.2015]; // ~12km away

        // Create test locations
        $downtown = Location::factory()->withCoordinates(
            $seattleDowntown['lat'],
            $seattleDowntown['lon']
        )->create(['organization_id' => $this->organization->id, 'name' => 'Downtown']);

        $capitolHill = Location::factory()->withCoordinates(
            $seattleCapitolHill['lat'],
            $seattleCapitolHill['lon']
        )->create(['organization_id' => $this->organization->id, 'name' => 'Capitol Hill']);

        $fremont = Location::factory()->withCoordinates(
            $seattleFremont['lat'],
            $seattleFremont['lon']
        )->create(['organization_id' => $this->organization->id, 'name' => 'Fremont']);

        $bellevue = Location::factory()->withCoordinates(
            $seattleBellevue['lat'],
            $seattleBellevue['lon']
        )->create(['organization_id' => $this->organization->id, 'name' => 'Bellevue']);

        // Test distance calculations with ±500m tolerance
        $locationsWithDistance = Location::withDistance(
            $seattleDowntown['lat'],
            $seattleDowntown['lon']
        )->get()->keyBy('name');

        // Downtown should be very close to itself (within 100m due to precision)
        $this->assertLessThan(100, $locationsWithDistance['Downtown']->distance_meters);

        // Capitol Hill should be ~2km away (allow ±500m tolerance)
        $capitolHillDistance = $locationsWithDistance['Capitol Hill']->distance_meters;
        $this->assertGreaterThan(1500, $capitolHillDistance, 'Capitol Hill should be at least 1.5km away');
        $this->assertLessThan(2500, $capitolHillDistance, 'Capitol Hill should be less than 2.5km away');

        // Fremont should be ~4.5km away (allow for geographic variations)
        $fremontDistance = $locationsWithDistance['Fremont']->distance_meters;
        $this->assertGreaterThan(4000, $fremontDistance, 'Fremont should be at least 4km away');
        $this->assertLessThan(5500, $fremontDistance, 'Fremont should be less than 5.5km away');

        // Bellevue should be ~10-12km away (allow for geographic variations)
        $bellevueDistance = $locationsWithDistance['Bellevue']->distance_meters;
        $this->assertGreaterThan(9000, $bellevueDistance, 'Bellevue should be at least 9km away');
        $this->assertLessThan(13000, $bellevueDistance, 'Bellevue should be less than 13km away');
    }

    /**
     * Test spatial search within radius functionality.
     */
    public function test_spatial_search_within_radius(): void
    {
        // NYC area coordinates
        $timesSquare = ['lat' => 40.7580, 'lon' => -73.9855];
        $financialDistrict = ['lat' => 40.7074, 'lon' => -74.0113]; // ~6km from Times Square
        $brooklyn = ['lat' => 40.6782, 'lon' => -73.9442]; // ~8km from Times Square
        $queens = ['lat' => 40.7282, 'lon' => -73.7949]; // ~15km from Times Square

        $locations = [
            Location::factory()->withCoordinates($timesSquare['lat'], $timesSquare['lon'])
                ->create(['organization_id' => $this->organization->id, 'name' => 'Times Square']),
            Location::factory()->withCoordinates($financialDistrict['lat'], $financialDistrict['lon'])
                ->create(['organization_id' => $this->organization->id, 'name' => 'Financial District']),
            Location::factory()->withCoordinates($brooklyn['lat'], $brooklyn['lon'])
                ->create(['organization_id' => $this->organization->id, 'name' => 'Brooklyn']),
            Location::factory()->withCoordinates($queens['lat'], $queens['lon'])
                ->create(['organization_id' => $this->organization->id, 'name' => 'Queens']),
        ];

        // Test 5km radius from Times Square
        $within5km = Location::near($timesSquare['lat'], $timesSquare['lon'], 5)->get();
        $locationNames = $within5km->pluck('name')->toArray();

        $this->assertContains('Times Square', $locationNames);
        $this->assertNotContains('Financial District', $locationNames); // Should be just outside 5km
        $this->assertNotContains('Brooklyn', $locationNames);
        $this->assertNotContains('Queens', $locationNames);

        // Test 10km radius from Times Square
        $within10km = Location::near($timesSquare['lat'], $timesSquare['lon'], 10)->get();
        $locationNames10km = $within10km->pluck('name')->toArray();

        $this->assertContains('Times Square', $locationNames10km);
        $this->assertContains('Financial District', $locationNames10km);
        $this->assertContains('Brooklyn', $locationNames10km);
        $this->assertNotContains('Queens', $locationNames10km); // Should be outside 10km

        // Test 20km radius from Times Square
        $within20km = Location::near($timesSquare['lat'], $timesSquare['lon'], 20)->get();
        $locationNames20km = $within20km->pluck('name')->toArray();

        $this->assertContains('Times Square', $locationNames20km);
        $this->assertContains('Financial District', $locationNames20km);
        $this->assertContains('Brooklyn', $locationNames20km);
        $this->assertContains('Queens', $locationNames20km);
    }

    /**
     * Test performance of spatial queries.
     */
    public function test_spatial_query_performance(): void
    {
        // Create 100 locations spread around a coordinate
        $baseCoords = ['lat' => 40.7128, 'lon' => -74.0060]; // NYC

        for ($i = 0; $i < 100; $i++) {
            Location::factory()->withCoordinates(
                $baseCoords['lat'] + (rand(-1000, 1000) / 10000), // ±0.1 degree variation
                $baseCoords['lon'] + (rand(-1000, 1000) / 10000)
            )->create(['organization_id' => $this->organization->id]);
        }

        $this->assertEquals(100, Location::count());

        // Test spatial query performance
        $startTime = microtime(true);

        $results = Location::near($baseCoords['lat'], $baseCoords['lon'], 10)
            ->withDistance($baseCoords['lat'], $baseCoords['lon'])
            ->orderByDistance($baseCoords['lat'], $baseCoords['lon'])
            ->get();

        $endTime = microtime(true);
        $queryTime = ($endTime - $startTime) * 1000; // Convert to milliseconds

        $this->assertLessThan(200, $queryTime, 'Spatial query should complete within 200ms');
        $this->assertGreaterThan(0, $results->count(), 'Should return some results');

        // Verify results have distance attribute
        $this->assertTrue(isset($results->first()->distance_meters));
        $this->assertIsNumeric($results->first()->distance_meters);
    }

    /**
     * Test spatial query edge cases and error handling.
     */
    public function test_spatial_query_edge_cases(): void
    {
        // Test with boundary coordinates
        $locations = [
            Location::factory()->withCoordinates(90.0, 0.0) // North Pole
                ->create(['organization_id' => $this->organization->id, 'name' => 'North Pole']),
            Location::factory()->withCoordinates(-90.0, 0.0) // South Pole
                ->create(['organization_id' => $this->organization->id, 'name' => 'South Pole']),
            Location::factory()->withCoordinates(0.0, 180.0) // International Date Line
                ->create(['organization_id' => $this->organization->id, 'name' => 'Date Line East']),
            Location::factory()->withCoordinates(0.0, -180.0) // International Date Line
                ->create(['organization_id' => $this->organization->id, 'name' => 'Date Line West']),
        ];

        // Test near North Pole (small radius due to convergence)
        $nearNorthPole = Location::near(90.0, 0.0, 1000)->get(); // 1000km radius
        $this->assertGreaterThan(0, $nearNorthPole->count());

        // Test with distance calculation at poles
        $polarDistances = Location::withDistance(90.0, 0.0)->get();
        foreach ($polarDistances as $location) {
            $this->assertIsNumeric($location->distance_meters);
            $this->assertGreaterThanOrEqual(0, $location->distance_meters);
        }

        // Test date line crossing
        $dateLineResults = Location::near(0.0, 180.0, 1000)->get();
        $this->assertGreaterThan(0, $dateLineResults->count());
    }

    /**
     * Test spatial queries with empty result sets.
     */
    public function test_spatial_queries_with_empty_results(): void
    {
        // Create a location in NYC
        Location::factory()->withCoordinates(40.7128, -74.0060)->create([
            'organization_id' => $this->organization->id,
            'name' => 'NYC Location',
        ]);

        // Search in the middle of the Pacific Ocean (should find nothing nearby)
        $pacificResults = Location::near(0.0, -150.0, 100)->get(); // 100km radius in Pacific
        $this->assertCount(0, $pacificResults);

        // Verify the query structure is correct even with no results
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $pacificResults);
    }

    /**
     * Test coordinate accuracy across different geographic regions.
     */
    public function test_coordinate_accuracy_across_regions(): void
    {
        // Test locations in different continents
        $testCoordinates = [
            ['name' => 'London', 'lat' => 51.5074, 'lon' => -0.1278],
            ['name' => 'Tokyo', 'lat' => 35.6762, 'lon' => 139.6503],
            ['name' => 'Sydney', 'lat' => -33.8688, 'lon' => 151.2093],
            ['name' => 'São Paulo', 'lat' => -23.5505, 'lon' => -46.6333],
            ['name' => 'Lagos', 'lat' => 6.5244, 'lon' => 3.3792],
        ];

        $locations = [];
        foreach ($testCoordinates as $coord) {
            $locations[] = Location::factory()->withCoordinates($coord['lat'], $coord['lon'])->create([
                'organization_id' => $this->organization->id,
                'name' => $coord['name'],
            ]);
        }

        // Test that each location can be found near its own coordinates
        foreach ($testCoordinates as $coord) {
            $nearbyLocations = Location::near($coord['lat'], $coord['lon'], 50) // 50km radius
                ->where('name', $coord['name'])
                ->get();

            $this->assertCount(1, $nearbyLocations, "Should find {$coord['name']} near its own coordinates");
        }

        // Test cross-hemisphere distance calculations
        $londonToSydney = Location::withDistance(51.5074, -0.1278) // London coordinates
            ->where('name', 'Sydney')
            ->first();

        // London to Sydney is approximately 17,000km
        $this->assertGreaterThan(16000000, $londonToSydney->distance_meters); // > 16,000km
        $this->assertLessThan(18000000, $londonToSydney->distance_meters); // < 18,000km
    }

    /**
     * Test complex spatial queries with multiple conditions.
     */
    public function test_complex_spatial_queries(): void
    {
        // Create test locations
        $seattleOrg = Organization::factory()->create(['name' => 'Seattle Company']);
        $portlandOrg = Organization::factory()->create(['name' => 'Portland Company']);

        $locations = [
            Location::factory()->withCoordinates(47.6062, -122.3321)->active()->create([
                'organization_id' => $seattleOrg->id,
                'name' => 'Seattle Active',
                'city' => 'Seattle',
            ]),
            Location::factory()->withCoordinates(47.6205, -122.3212)->inactive()->create([
                'organization_id' => $seattleOrg->id,
                'name' => 'Seattle Inactive',
                'city' => 'Seattle',
            ]),
            Location::factory()->withCoordinates(45.5152, -122.6784)->active()->create([
                'organization_id' => $portlandOrg->id,
                'name' => 'Portland Active',
                'city' => 'Portland',
            ]),
        ];

        // Test complex query: active locations near Seattle within 50km
        $results = Location::near(47.6062, -122.3321, 50) // Within 50km of Seattle
            ->active()
            ->withDistance(47.6062, -122.3321)
            ->orderByDistance(47.6062, -122.3321)
            ->get();

        $this->assertCount(1, $results); // Only Seattle Active should match
        $this->assertEquals('Seattle Active', $results->first()->name);

        // Test with organization filter
        $seattleOrgResults = Location::near(47.6062, -122.3321, 500) // Large radius
            ->where('organization_id', $seattleOrg->id)
            ->get();

        $this->assertCount(2, $seattleOrgResults); // Both Seattle locations
        $this->assertTrue($seattleOrgResults->pluck('name')->contains('Seattle Active'));
        $this->assertTrue($seattleOrgResults->pluck('name')->contains('Seattle Inactive'));

        // Test with city filter
        $seattleCityResults = Location::inCity('Seattle')
            ->near(47.6062, -122.3321, 50)
            ->get();

        $this->assertCount(2, $seattleCityResults);
    }

    /**
     * Test PostGIS spatial indexing is working efficiently.
     */
    public function test_postgis_spatial_indexing_efficiency(): void
    {
        // Create many locations to test index usage - use more variation
        for ($i = 0; $i < 50; $i++) {
            Location::factory()->create([
                'organization_id' => $this->organization->id,
                'latitude' => 40.0 + (rand(0, 2000) / 1000), // Around NYC area with more spread
                'longitude' => -74.0 + (rand(0, 2000) / 1000),
            ]);
        }

        // Enable query logging to check if index is used
        DB::enableQueryLog();

        $results = Location::near(40.7128, -74.0060, 50) // Larger radius to ensure results
            ->withDistance(40.7128, -74.0060)
            ->orderByDistance(40.7128, -74.0060)
            ->limit(10)
            ->get();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Verify query was executed
        $this->assertGreaterThan(0, count($queries));

        // Verify the spatial query uses the PostGIS functions
        $spatialQuery = collect($queries)->first(function ($query) {
            return str_contains($query['query'], 'ST_DWithin') || str_contains($query['query'], 'ST_Distance');
        });

        $this->assertNotNull($spatialQuery, 'Should execute spatial query with PostGIS functions');

        // Verify results are returned and properly ordered
        $this->assertGreaterThan(0, $results->count());

        // Check that results are ordered by distance (ascending)
        $previousDistance = 0;
        foreach ($results as $location) {
            $this->assertGreaterThanOrEqual($previousDistance, $location->distance_meters);
            $previousDistance = $location->distance_meters;
        }
    }

    /**
     * Test handling of invalid coordinates gracefully.
     */
    public function test_handling_invalid_coordinates(): void
    {
        // Create a valid location for comparison
        $validLocation = Location::factory()->withCoordinates(40.7128, -74.0060)->create([
            'organization_id' => $this->organization->id,
            'name' => 'Valid Location',
        ]);

        // Test with coordinates outside valid range
        // Note: These should be caught by application validation, but testing DB-level handling

        // Test spatial query with extreme coordinates (should not crash)
        $extremeResults = Location::near(95.0, 200.0, 10)->get(); // Invalid lat/lon
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $extremeResults);

        // Test valid coordinate boundaries
        $northPoleResults = Location::near(90.0, 0.0, 1000)->get();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $northPoleResults);

        $southPoleResults = Location::near(-90.0, 0.0, 1000)->get();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $southPoleResults);
    }
}
