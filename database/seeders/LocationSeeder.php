<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Location seeder for WDTP platform sample data.
 *
 * Creates sample locations across major US cities linked to existing organizations.
 * Uses realistic coordinates and addresses for testing spatial queries.
 */
class LocationSeeder extends Seeder
{
    /**
     * Sample locations across major US cities
     */
    private array $sampleLocations = [
        // New York City
        [
            'name' => 'Times Square',
            'city' => 'New York',
            'state' => 'NY',
            'lat' => 40.7580,
            'lon' => -73.9855,
            'address' => '1515 Broadway',
            'postal_code' => '10036',
        ],
        [
            'name' => 'Financial District',
            'city' => 'New York',
            'state' => 'NY',
            'lat' => 40.7074,
            'lon' => -74.0113,
            'address' => '150 Broadway',
            'postal_code' => '10038',
        ],

        // Los Angeles
        [
            'name' => 'Hollywood',
            'city' => 'Los Angeles',
            'state' => 'CA',
            'lat' => 34.0928,
            'lon' => -118.3287,
            'address' => '6801 Hollywood Blvd',
            'postal_code' => '90028',
        ],
        [
            'name' => 'Santa Monica',
            'city' => 'Los Angeles',
            'state' => 'CA',
            'lat' => 34.0195,
            'lon' => -118.4912,
            'address' => '1200 3rd St Promenade',
            'postal_code' => '90401',
        ],

        // Chicago
        [
            'name' => 'The Loop',
            'city' => 'Chicago',
            'state' => 'IL',
            'lat' => 41.8781,
            'lon' => -87.6298,
            'address' => '100 N LaSalle St',
            'postal_code' => '60602',
        ],
        [
            'name' => 'Magnificent Mile',
            'city' => 'Chicago',
            'state' => 'IL',
            'lat' => 41.8970,
            'lon' => -87.6240,
            'address' => '835 N Michigan Ave',
            'postal_code' => '60611',
        ],

        // Houston
        [
            'name' => 'Downtown',
            'city' => 'Houston',
            'state' => 'TX',
            'lat' => 29.7604,
            'lon' => -95.3698,
            'address' => '1200 McKinney St',
            'postal_code' => '77010',
        ],

        // Phoenix
        [
            'name' => 'Downtown',
            'city' => 'Phoenix',
            'state' => 'AZ',
            'lat' => 33.4484,
            'lon' => -112.0740,
            'address' => '100 N 1st Ave',
            'postal_code' => '85003',
        ],

        // Philadelphia
        [
            'name' => 'Center City',
            'city' => 'Philadelphia',
            'state' => 'PA',
            'lat' => 39.9526,
            'lon' => -75.1652,
            'address' => '1500 Market St',
            'postal_code' => '19102',
        ],

        // San Antonio
        [
            'name' => 'Downtown',
            'city' => 'San Antonio',
            'state' => 'TX',
            'lat' => 29.4241,
            'lon' => -98.4936,
            'address' => '318 W Houston St',
            'postal_code' => '78205',
        ],

        // San Diego
        [
            'name' => 'Gaslamp Quarter',
            'city' => 'San Diego',
            'state' => 'CA',
            'lat' => 32.7157,
            'lon' => -117.1611,
            'address' => '600 F St',
            'postal_code' => '92101',
        ],

        // Dallas
        [
            'name' => 'Deep Ellum',
            'city' => 'Dallas',
            'state' => 'TX',
            'lat' => 32.7767,
            'lon' => -96.7970,
            'address' => '2600 Main St',
            'postal_code' => '75226',
        ],

        // San Jose
        [
            'name' => 'Downtown',
            'city' => 'San Jose',
            'state' => 'CA',
            'lat' => 37.3382,
            'lon' => -121.8863,
            'address' => '150 W San Carlos St',
            'postal_code' => '95113',
        ],

        // Austin
        [
            'name' => 'Sixth Street',
            'city' => 'Austin',
            'state' => 'TX',
            'lat' => 30.2672,
            'lon' => -97.7431,
            'address' => '600 E 6th St',
            'postal_code' => '78701',
        ],

        // Seattle
        [
            'name' => 'Pike Place Market',
            'city' => 'Seattle',
            'state' => 'WA',
            'lat' => 47.6062,
            'lon' => -122.3321,
            'address' => '85 Pike St',
            'postal_code' => '98101',
        ],

        // Denver
        [
            'name' => 'LoDo',
            'city' => 'Denver',
            'state' => 'CO',
            'lat' => 39.7392,
            'lon' => -104.9903,
            'address' => '1600 17th St',
            'postal_code' => '80202',
        ],

        // Boston
        [
            'name' => 'Back Bay',
            'city' => 'Boston',
            'state' => 'MA',
            'lat' => 42.3601,
            'lon' => -71.0589,
            'address' => '800 Boylston St',
            'postal_code' => '02199',
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating sample locations across major US cities...');

        // Get some organizations to associate locations with
        $organizations = Organization::where('is_active', true)
            ->limit(10)
            ->get();

        if ($organizations->isEmpty()) {
            $this->command->warn('No active organizations found. Creating sample organizations first...');

            // Create some sample organizations if none exist
            $organizations = Organization::factory()
                ->count(5)
                ->active()
                ->verified()
                ->create();
        }

        $createdCount = 0;

        foreach ($this->sampleLocations as $locationData) {
            // Randomly assign an organization
            $organization = $organizations->random();

            // Create the location
            $location = Location::factory()
                ->withCoordinates($locationData['lat'], $locationData['lon'])
                ->active()
                ->create([
                    'organization_id' => $organization->id,
                    'name' => $organization->name.' - '.$locationData['name'],
                    'address_line_1' => $locationData['address'],
                    'city' => $locationData['city'],
                    'state_province' => $locationData['state'],
                    'postal_code' => $locationData['postal_code'],
                    'country_code' => 'US',
                    'latitude' => $locationData['lat'],
                    'longitude' => $locationData['lon'],
                    'is_verified' => fake()->boolean(40), // 40% chance of being verified
                ]);

            $createdCount++;
            $this->command->info("Created location: {$location->name} in {$location->city}, {$location->state_province}");
        }

        $this->command->info("Successfully created {$createdCount} sample locations.");
        $this->command->info('Sample locations span major US cities with realistic coordinates for spatial testing.');
    }
}
