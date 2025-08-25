<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Organization;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocationSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test seeder runs without errors.
     */
    public function test_seeder_runs_without_errors(): void
    {
        // Ensure we have organizations for locations to link to
        Organization::factory()->count(5)->active()->verified()->create();

        // Run the seeder using Artisan to properly set up command context
        Artisan::call('db:seed', ['--class' => LocationSeeder::class]);

        // Should complete without throwing exceptions
        $this->assertTrue(true, 'Seeder completed successfully');
    }

    /**
     * Test seeder creates expected number of locations.
     */
    public function test_seeder_creates_expected_number_of_locations(): void
    {
        // Ensure we have organizations
        Organization::factory()->count(5)->active()->verified()->create();

        $initialCount = Location::count();

        // Run seeder
        Artisan::call('db:seed', ['--class' => LocationSeeder::class]);

        $finalCount = Location::count();
        $locationsCreated = $finalCount - $initialCount;

        // Should create 17 locations (based on seeder data array)
        $this->assertEquals(17, $locationsCreated, 'Should create exactly 17 locations');
    }

    /**
     * Test seeder creates organizations if none exist.
     */
    public function test_seeder_creates_organizations_if_none_exist(): void
    {
        // Start with no organizations
        $this->assertEquals(0, Organization::count());

        // Run seeder
        Artisan::call('db:seed', ['--class' => LocationSeeder::class]);

        // Should create organizations and locations
        $this->assertGreaterThan(0, Organization::count(), 'Should create organizations');
        $this->assertEquals(17, Location::count(), 'Should create 17 locations');
    }

    /**
     * Test seeder creates locations with correct data quality.
     */
    public function test_seeder_creates_locations_with_correct_data_quality(): void
    {
        Organization::factory()->count(5)->active()->verified()->create();

        Artisan::call('db:seed', ['--class' => LocationSeeder::class]);

        $locations = Location::all();
        $this->assertCount(17, $locations);

        foreach ($locations as $location) {
            // Test required fields are populated
            $this->assertNotNull($location->organization_id);
            $this->assertNotNull($location->name);
            $this->assertNotNull($location->address_line_1);
            $this->assertNotNull($location->city);
            $this->assertNotNull($location->state_province);
            $this->assertNotNull($location->postal_code);
            $this->assertNotNull($location->latitude);
            $this->assertNotNull($location->longitude);

            // Test data types (coordinates should be castable to float)
            $this->assertIsNumeric($location->latitude);
            $this->assertIsNumeric($location->longitude);
            $this->assertTrue($location->is_active); // All seeded locations should be active
            $this->assertEquals('US', $location->country_code);

            // Test organization relationship exists
            $this->assertInstanceOf(Organization::class, $location->organization);
        }
    }

    /**
     * Test seeder creates locations with accurate coordinates.
     */
    public function test_seeder_creates_locations_with_accurate_coordinates(): void
    {
        Organization::factory()->count(5)->active()->verified()->create();

        Artisan::call('db:seed', ['--class' => LocationSeeder::class]);

        $locations = Location::all();

        // Define expected coordinates for major locations
        $expectedCoordinates = [
            'Times Square' => ['lat' => 40.7580, 'lon' => -73.9855],
            'Financial District' => ['lat' => 40.7074, 'lon' => -74.0113],
            'Hollywood' => ['lat' => 34.0928, 'lon' => -118.3287],
            'Santa Monica' => ['lat' => 34.0195, 'lon' => -118.4912],
            'The Loop' => ['lat' => 41.8781, 'lon' => -87.6298],
            'Pike Place Market' => ['lat' => 47.6062, 'lon' => -122.3321],
        ];

        foreach ($expectedCoordinates as $locationName => $coords) {
            $location = $locations->first(function ($loc) use ($locationName) {
                return str_contains($loc->name, $locationName);
            });

            if ($location) {
                $this->assertEquals(
                    $coords['lat'],
                    $location->latitude,
                    "Latitude for {$locationName} should match expected value",
                    0.0001 // Allow small precision tolerance
                );
                $this->assertEquals(
                    $coords['lon'],
                    $location->longitude,
                    "Longitude for {$locationName} should match expected value",
                    0.0001
                );
            }
        }
    }

    /**
     * Test seeder creates locations across expected cities.
     */
    public function test_seeder_creates_locations_across_expected_cities(): void
    {
        Organization::factory()->count(5)->active()->verified()->create();

        Artisan::call('db:seed', ['--class' => LocationSeeder::class]);

        $locations = Location::all();
        $cities = $locations->pluck('city')->unique()->toArray();

        // Should include major US cities
        $expectedCities = [
            'New York', 'Los Angeles', 'Chicago', 'Houston', 'Phoenix',
            'Philadelphia', 'San Antonio', 'San Diego', 'Dallas', 'San Jose',
            'Austin', 'Seattle', 'Denver', 'Boston',
        ];

        foreach ($expectedCities as $expectedCity) {
            $this->assertContains(
                $expectedCity,
                $cities,
                "Should include location in {$expectedCity}"
            );
        }
    }

    /**
     * Test seeder distributes locations across multiple organizations.
     */
    public function test_seeder_distributes_locations_across_organizations(): void
    {
        Organization::factory()->count(5)->active()->verified()->create();

        Artisan::call('db:seed', ['--class' => LocationSeeder::class]);

        $locations = Location::all();
        $organizationIds = $locations->pluck('organization_id')->unique();

        // Should use multiple organizations (not all assigned to one)
        $this->assertGreaterThan(1, $organizationIds->count(), 'Should distribute across multiple organizations');

        // Each organization should have at least one location
        foreach ($organizationIds as $orgId) {
            $orgLocations = $locations->where('organization_id', $orgId);
            $this->assertGreaterThan(0, $orgLocations->count());
        }
    }

    /**
     * Test seeder creates PostGIS spatial points correctly.
     */
    public function test_seeder_creates_postgis_spatial_points(): void
    {
        Organization::factory()->count(5)->active()->verified()->create();

        Artisan::call('db:seed', ['--class' => LocationSeeder::class]);

        $locations = Location::all();

        foreach ($locations as $location) {
            // Test that spatial queries work immediately
            $nearby = Location::near($location->latitude, $location->longitude, 1)->get();
            $this->assertTrue(
                $nearby->contains($location),
                "Location '{$location->name}' should be findable by spatial query"
            );

            // Verify point column is set in database
            $pointData = DB::table('locations')
                ->select(DB::raw('ST_X(point::geometry) as lon, ST_Y(point::geometry) as lat'))
                ->where('id', $location->id)
                ->first();

            $this->assertNotNull($pointData, "Point data should exist for '{$location->name}'");
            $this->assertEquals(
                $location->longitude,
                round($pointData->lon, 4),
                "Database point longitude should match model for '{$location->name}'"
            );
            $this->assertEquals(
                $location->latitude,
                round($pointData->lat, 4),
                "Database point latitude should match model for '{$location->name}'"
            );
        }
    }

    /**
     * Test seeder creates locations with proper name format.
     */
    public function test_seeder_creates_locations_with_proper_name_format(): void
    {
        $testOrg = Organization::factory()->create(['name' => 'Test Company']);

        Artisan::call('db:seed', ['--class' => LocationSeeder::class]);

        $locations = Location::all();

        foreach ($locations as $location) {
            // Names should include organization name + location descriptor
            $this->assertStringContainsString(' - ', $location->name, 'Name should contain separator');

            $nameParts = explode(' - ', $location->name);
            $this->assertCount(2, $nameParts, 'Name should have organization and location parts');

            $organizationPart = $nameParts[0];
            $locationPart = $nameParts[1];

            $this->assertNotEmpty($organizationPart, 'Organization part should not be empty');
            $this->assertNotEmpty($locationPart, 'Location part should not be empty');

            // Organization part should match an existing organization
            $this->assertTrue(
                Organization::where('name', $organizationPart)->exists(),
                "Organization '{$organizationPart}' should exist in database"
            );
        }
    }

    /**
     * Test seeder handles verification status appropriately.
     */
    public function test_seeder_handles_verification_status(): void
    {
        Organization::factory()->count(5)->active()->verified()->create();

        Artisan::call('db:seed', ['--class' => LocationSeeder::class]);

        $locations = Location::all();

        $verifiedCount = $locations->where('is_verified', true)->count();
        $unverifiedCount = $locations->where('is_verified', false)->count();

        // Should have a mix of verified and unverified (seeder uses 40% chance)
        $this->assertGreaterThan(0, $verifiedCount, 'Should have some verified locations');
        $this->assertGreaterThan(0, $unverifiedCount, 'Should have some unverified locations');

        // Total should be 17
        $this->assertEquals(17, $verifiedCount + $unverifiedCount);
    }

    /**
     * Test seeder creates locations with proper address format.
     */
    public function test_seeder_creates_locations_with_proper_address_format(): void
    {
        Organization::factory()->count(5)->active()->verified()->create();

        Artisan::call('db:seed', ['--class' => LocationSeeder::class]);

        $locations = Location::all();

        // Test specific known addresses from seeder
        $knownAddresses = [
            '1515 Broadway' => 'New York',
            '150 Broadway' => 'New York',
            '6801 Hollywood Blvd' => 'Los Angeles',
            '1200 3rd St Promenade' => 'Los Angeles',
            '100 N LaSalle St' => 'Chicago',
            '85 Pike St' => 'Seattle',
        ];

        foreach ($knownAddresses as $address => $city) {
            $location = $locations->firstWhere('address_line_1', $address);
            if ($location) {
                $this->assertEquals($city, $location->city, "Address {$address} should be in {$city}");
                $this->assertMatchesRegularExpression(
                    '/^\d+\s+.+/',
                    $location->address_line_1,
                    'Address should start with street number'
                );
            }
        }

        // All locations should have valid postal codes
        foreach ($locations as $location) {
            $this->assertMatchesRegularExpression(
                '/^\d{5}$/',
                $location->postal_code,
                'Postal code should be 5 digits'
            );
        }
    }

    /**
     * Test seeder performance and efficiency.
     */
    public function test_seeder_performance(): void
    {
        Organization::factory()->count(5)->active()->verified()->create();

        $startTime = microtime(true);

        Artisan::call('db:seed', ['--class' => LocationSeeder::class]);

        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000; // Convert to milliseconds

        $this->assertLessThan(5000, $executionTime, 'Seeder should complete within 5 seconds');
        $this->assertEquals(17, Location::count(), 'Should create exactly 17 locations');
    }

    /**
     * Test seeder can be run multiple times safely.
     */
    public function test_seeder_can_be_run_multiple_times(): void
    {
        Organization::factory()->count(5)->active()->verified()->create();

        // Run seeder first time
        Artisan::call('db:seed', ['--class' => LocationSeeder::class]);
        $firstRunCount = Location::count();
        $this->assertEquals(17, $firstRunCount);

        // Run seeder second time - should create additional locations
        Artisan::call('db:seed', ['--class' => LocationSeeder::class]);
        $secondRunCount = Location::count();
        $this->assertEquals(34, $secondRunCount, 'Should create additional 17 locations on second run');

        // All locations should still be valid
        $allLocations = Location::all();
        foreach ($allLocations as $location) {
            $this->assertNotNull($location->organization_id);
            $this->assertNotNull($location->latitude);
            $this->assertNotNull($location->longitude);

            // Should be findable by spatial query
            $nearby = Location::near($location->latitude, $location->longitude, 1)->get();
            $this->assertTrue($nearby->contains($location));
        }
    }

    /**
     * Test seeder geographic distribution covers expected regions.
     */
    public function test_seeder_geographic_distribution(): void
    {
        Organization::factory()->count(5)->active()->verified()->create();

        Artisan::call('db:seed', ['--class' => LocationSeeder::class]);

        $locations = Location::all();

        // Group by general regions
        $westCoast = $locations->whereIn('city', ['Los Angeles', 'San Diego', 'San Jose', 'Seattle']);
        $eastCoast = $locations->whereIn('city', ['New York', 'Philadelphia', 'Boston']);
        $midwest = $locations->whereIn('city', ['Chicago']);
        $south = $locations->whereIn('city', ['Houston', 'San Antonio', 'Dallas', 'Austin']);
        $mountain = $locations->whereIn('city', ['Phoenix', 'Denver']);

        // Should have representation from multiple regions
        $this->assertGreaterThan(0, $westCoast->count(), 'Should have West Coast locations');
        $this->assertGreaterThan(0, $eastCoast->count(), 'Should have East Coast locations');
        $this->assertGreaterThan(0, $midwest->count(), 'Should have Midwest locations');
        $this->assertGreaterThan(0, $south->count(), 'Should have Southern locations');
        $this->assertGreaterThan(0, $mountain->count(), 'Should have Mountain/Southwest locations');

        // Test coordinate boundaries are realistic for US
        $minLat = $locations->min('latitude');
        $maxLat = $locations->max('latitude');
        $minLon = $locations->min('longitude');
        $maxLon = $locations->max('longitude');

        $this->assertGreaterThan(25, $minLat, 'Minimum latitude should be above southern US border');
        $this->assertLessThan(50, $maxLat, 'Maximum latitude should be below northern US border');
        $this->assertGreaterThan(-130, $minLon, 'Minimum longitude should be within continental US');
        $this->assertLessThan(-65, $maxLon, 'Maximum longitude should be within continental US');
    }

    /**
     * Test seeder console output provides useful information.
     */
    public function test_seeder_console_output(): void
    {
        Organization::factory()->count(3)->active()->verified()->create();

        $exitCode = Artisan::call('db:seed', ['--class' => LocationSeeder::class]);
        $output = Artisan::output();

        $this->assertEquals(0, $exitCode, 'Seeder should exit successfully');
        $this->assertStringContainsString('Creating sample locations', $output);
        $this->assertStringContainsString('Successfully created', $output);
        $this->assertStringContainsString('17', $output); // Number of locations created

        // Should show progress for created locations
        $lines = explode("\n", $output);
        $createdLines = array_filter($lines, fn ($line) => str_contains($line, 'Created location:'));
        $this->assertCount(17, $createdLines, 'Should show creation message for each location');
    }
}
