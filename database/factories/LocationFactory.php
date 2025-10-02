<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Location>
 */
class LocationFactory extends Factory
{
    /**
     * Major US cities with realistic coordinates for testing
     */
    private static array $usCities = [
        ['name' => 'New York', 'state' => 'NY', 'lat' => 40.7128, 'lon' => -74.0060],
        ['name' => 'Los Angeles', 'state' => 'CA', 'lat' => 34.0522, 'lon' => -118.2437],
        ['name' => 'Chicago', 'state' => 'IL', 'lat' => 41.8781, 'lon' => -87.6298],
        ['name' => 'Houston', 'state' => 'TX', 'lat' => 29.7604, 'lon' => -95.3698],
        ['name' => 'Phoenix', 'state' => 'AZ', 'lat' => 33.4484, 'lon' => -112.0740],
        ['name' => 'Philadelphia', 'state' => 'PA', 'lat' => 39.9526, 'lon' => -75.1652],
        ['name' => 'San Antonio', 'state' => 'TX', 'lat' => 29.4241, 'lon' => -98.4936],
        ['name' => 'San Diego', 'state' => 'CA', 'lat' => 32.7157, 'lon' => -117.1611],
        ['name' => 'Dallas', 'state' => 'TX', 'lat' => 32.7767, 'lon' => -96.7970],
        ['name' => 'San Jose', 'state' => 'CA', 'lat' => 37.3382, 'lon' => -121.8863],
        ['name' => 'Austin', 'state' => 'TX', 'lat' => 30.2672, 'lon' => -97.7431],
        ['name' => 'Jacksonville', 'state' => 'FL', 'lat' => 30.3322, 'lon' => -81.6557],
        ['name' => 'Fort Worth', 'state' => 'TX', 'lat' => 32.7555, 'lon' => -97.3308],
        ['name' => 'Columbus', 'state' => 'OH', 'lat' => 39.9612, 'lon' => -82.9988],
        ['name' => 'Charlotte', 'state' => 'NC', 'lat' => 35.2271, 'lon' => -80.8431],
        ['name' => 'San Francisco', 'state' => 'CA', 'lat' => 37.7749, 'lon' => -122.4194],
        ['name' => 'Indianapolis', 'state' => 'IN', 'lat' => 39.7684, 'lon' => -86.1581],
        ['name' => 'Seattle', 'state' => 'WA', 'lat' => 47.6062, 'lon' => -122.3321],
        ['name' => 'Denver', 'state' => 'CO', 'lat' => 39.7392, 'lon' => -104.9903],
        ['name' => 'Boston', 'state' => 'MA', 'lat' => 42.3601, 'lon' => -71.0589],
    ];

    /**
     * Common business location descriptors
     */
    private static array $locationDescriptors = [
        'Downtown', 'Uptown', 'Mall', 'Plaza', 'Center', 'Square', 'Main Street',
        'North', 'South', 'East', 'West', 'Airport', 'Station', 'Park', 'University',
        'Historic District', 'Financial District', 'Shopping Center', 'Town Center',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Select a random US city
        $cityData = fake()->randomElement(self::$usCities);

        // Add some random variation to coordinates (within ~1 mile radius)
        $latVariation = fake()->randomFloat(4, -0.01, 0.01);
        $lonVariation = fake()->randomFloat(4, -0.01, 0.01);

        $latitude = $cityData['lat'] + $latVariation;
        $longitude = $cityData['lon'] + $lonVariation;

        // Generate location name
        $descriptor = fake()->randomElement(self::$locationDescriptors);
        $locationName = $descriptor;

        // Generate address
        $streetNumber = fake()->numberBetween(100, 9999);
        $streetName = fake()->streetName();
        $addressLine1 = $streetNumber.' '.$streetName;

        // Generate slug from location name and city
        $slugBase = $locationName.' '.$cityData['name'].' '.$cityData['state'];
        $slug = Str::slug($slugBase).'-'.fake()->randomNumber(3, true);

        return [
            'organization_id' => Organization::factory(),
            'name' => $locationName,
            'slug' => $slug,
            'address_line_1' => $addressLine1,
            'address_line_2' => fake()->optional(0.2)->randomElement(['Suite A', 'Unit B', 'Floor 2', 'Apt 101']),
            'city' => $cityData['name'],
            'state_province' => $cityData['state'],
            'postal_code' => fake()->postcode(),
            'country_code' => 'US',
            'phone' => fake()->optional(0.7)->phoneNumber(),
            'website_url' => fake()->optional(0.3)->url(),
            'description' => fake()->optional(0.4)->sentence(),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'is_active' => fake()->boolean(90), // 90% active
            'is_verified' => fake()->boolean(30), // 30% verified
            'verification_notes' => null,
            'osm_id' => null,
            'osm_type' => null,
            'osm_data' => null,
        ];
    }

    /**
     * Configure after making to set the PostGIS point column
     */
    public function configure(): static
    {
        return $this->afterCreating(function ($location) {
            // Update the PostGIS point column using raw SQL
            \DB::table('locations')
                ->where('id', $location->id)
                ->update([
                    'point' => \DB::raw("ST_SetSRID(ST_MakePoint({$location->longitude}, {$location->latitude}), 4326)::geography"),
                ]);
        });
    }

    /**
     * Create an active location state.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    /**
     * Create an inactive location state.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Create a verified location state.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => true,
            'verification_notes' => fake()->optional(0.5)->sentence(),
        ]);
    }

    /**
     * Create a location with specific coordinates.
     */
    public function withCoordinates(float $latitude, float $longitude): static
    {
        return $this->state(fn (array $attributes) => [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    /**
     * Create a location in a specific city.
     */
    public function inCity(string $cityName): static
    {
        $cityData = collect(self::$usCities)->firstWhere('name', $cityName);

        if (! $cityData) {
            // Default to New York if city not found
            $cityData = self::$usCities[0];
        }

        // Add small random variation
        $latVariation = fake()->randomFloat(4, -0.005, 0.005);
        $lonVariation = fake()->randomFloat(4, -0.005, 0.005);

        return $this->state(fn (array $attributes) => [
            'city' => $cityData['name'],
            'state_province' => $cityData['state'],
            'latitude' => $cityData['lat'] + $latVariation,
            'longitude' => $cityData['lon'] + $lonVariation,
        ]);
    }

    /**
     * Create a location for New York City (commonly used in tests).
     */
    public function newYork(): static
    {
        return $this->inCity('New York');
    }

    /**
     * Create a location for Los Angeles (commonly used in tests).
     */
    public function losAngeles(): static
    {
        return $this->inCity('Los Angeles');
    }

    /**
     * Create a location for Chicago (commonly used in tests).
     */
    public function chicago(): static
    {
        return $this->inCity('Chicago');
    }

    /**
     * Create a location with OpenStreetMap data.
     */
    public function withOsmData(): static
    {
        return $this->state(function (array $attributes) {
            $osmType = fake()->randomElement(['node', 'way', 'relation']);
            $osmId = fake()->numberBetween(1000000, 999999999);

            // Generate realistic OSM tag data based on type
            $osmData = [
                'name' => $attributes['name'] ?? fake()->company(),
                'amenity' => fake()->randomElement(['restaurant', 'cafe', 'bank', 'pharmacy', 'shop', 'fast_food']),
                'addr:housenumber' => explode(' ', $attributes['address_line_1'] ?? '')[0] ?? fake()->buildingNumber(),
                'addr:street' => fake()->streetName(),
                'addr:city' => $attributes['city'] ?? fake()->city(),
                'addr:state' => $attributes['state_province'] ?? fake()->stateAbbr(),
                'addr:postcode' => $attributes['postal_code'] ?? fake()->postcode(),
            ];

            // Add optional tags
            if (fake()->boolean(40)) {
                $osmData['phone'] = $attributes['phone'] ?? fake()->phoneNumber();
            }
            if (fake()->boolean(30)) {
                $osmData['website'] = $attributes['website_url'] ?? fake()->url();
            }
            if (fake()->boolean(20)) {
                $osmData['opening_hours'] = 'Mo-Fr 09:00-17:00';
            }

            return [
                'osm_id' => $osmId,
                'osm_type' => $osmType,
                'osm_data' => $osmData,
            ];
        });
    }

    /**
     * Create a location with OSM node type.
     */
    public function osmNode(): static
    {
        return $this->withOsmData()->state(fn (array $attributes) => [
            'osm_type' => 'node',
        ]);
    }

    /**
     * Create a location with OSM way type.
     */
    public function osmWay(): static
    {
        return $this->withOsmData()->state(fn (array $attributes) => [
            'osm_type' => 'way',
        ]);
    }

    /**
     * Create a location with OSM relation type.
     */
    public function osmRelation(): static
    {
        return $this->withOsmData()->state(fn (array $attributes) => [
            'osm_type' => 'relation',
        ]);
    }
}
