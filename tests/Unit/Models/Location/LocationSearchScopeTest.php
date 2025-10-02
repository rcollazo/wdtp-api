<?php

namespace Tests\Unit\Models\Location;

use App\Models\Industry;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocationSearchScopeTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test organization with industry
        $industry = Industry::factory()->create(['name' => 'Food Service']);
        $this->organization = Organization::factory()->create([
            'primary_industry_id' => $industry->id,
            'name' => 'Test Organization',
        ]);
    }

    public function test_single_word_query_returns_matching_locations(): void
    {
        // Create test locations
        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'McDonald\'s Downtown',
            'city' => 'New York',
        ]);

        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Burger King',
            'city' => 'Los Angeles',
        ]);

        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Starbucks',
            'city' => 'Chicago',
        ]);

        // Search for "McDonald"
        $results = Location::searchByNameOrCategory('McDonald')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('McDonald\'s Downtown', $results->first()->name);
        $this->assertNotNull($results->first()->text_rank);
    }

    public function test_multi_word_query_converts_to_and_operator(): void
    {
        // Create test locations
        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Downtown Coffee Shop',
            'city' => 'Seattle',
        ]);

        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Downtown Pizza',
            'city' => 'Portland',
        ]);

        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Uptown Coffee Shop',
            'city' => 'Denver',
        ]);

        // Search for "downtown coffee" - both words must match
        $results = Location::searchByNameOrCategory('downtown coffee')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Downtown Coffee Shop', $results->first()->name);
    }

    public function test_query_with_special_characters_handled_safely(): void
    {
        // Create location with special characters
        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Joe\'s Café & Grill',
            'city' => 'Boston',
        ]);

        // Search with special characters should not cause SQL errors
        $results = Location::searchByNameOrCategory('Joe\'s')->get();

        // Should handle gracefully - may or may not match depending on normalization
        $this->assertIsObject($results);
    }

    public function test_empty_query_returns_no_results(): void
    {
        Location::factory()->count(3)->create([
            'organization_id' => $this->organization->id,
        ]);

        // Empty query should not match anything
        $results = Location::searchByNameOrCategory('')->get();

        $this->assertCount(0, $results);
    }

    public function test_no_matches_returns_empty_collection(): void
    {
        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'McDonald\'s',
            'city' => 'New York',
        ]);

        // Search for term that doesn't exist
        $results = Location::searchByNameOrCategory('nonexistent')->get();

        $this->assertCount(0, $results);
        $this->assertTrue($results->isEmpty());
    }

    public function test_text_rank_included_in_results(): void
    {
        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Coffee Shop',
            'city' => 'Seattle',
        ]);

        $results = Location::searchByNameOrCategory('coffee')->get();

        $this->assertNotEmpty($results);
        $this->assertObjectHasProperty('text_rank', $results->first());
        $this->assertIsNumeric($results->first()->text_rank);
    }

    public function test_text_rank_normalized_between_zero_and_one(): void
    {
        // Create multiple locations with varying relevance
        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Coffee Coffee Coffee',
            'address_line_1' => 'Coffee Street',
            'city' => 'Coffee City',
        ]);

        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Tea Shop',
            'address_line_1' => 'Coffee Avenue',
            'city' => 'Boston',
        ]);

        $results = Location::searchByNameOrCategory('coffee')->get();

        foreach ($results as $location) {
            $this->assertGreaterThanOrEqual(0, $location->text_rank, 'text_rank should be >= 0');
            $this->assertLessThanOrEqual(1.0, $location->text_rank, 'text_rank should be <= 1.0');
        }
    }

    public function test_zero_rank_results_excluded(): void
    {
        // All results should have text_rank > 0 since the scope filters by matching
        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Relevant Location',
            'city' => 'Seattle',
        ]);

        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Different Place',
            'city' => 'Portland',
        ]);

        $results = Location::searchByNameOrCategory('relevant')->get();

        foreach ($results as $location) {
            $this->assertGreaterThan(0, $location->text_rank, 'text_rank should be > 0 for matches');
        }
    }

    public function test_scope_chains_with_near(): void
    {
        // NYC coordinates
        $lat = 40.7128;
        $lon = -74.0060;

        Location::factory()->withCoordinates($lat, $lon)->create([
            'organization_id' => $this->organization->id,
            'name' => 'NYC Coffee Shop',
            'city' => 'New York',
        ]);

        Location::factory()->withCoordinates(34.0522, -118.2437)->create([
            'organization_id' => $this->organization->id,
            'name' => 'LA Coffee Shop',
            'city' => 'Los Angeles',
        ]);

        // Chain search with spatial filter
        $results = Location::searchByNameOrCategory('coffee')
            ->near($lat, $lon, 100) // 100km radius
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('NYC Coffee Shop', $results->first()->name);
    }

    public function test_scope_chains_with_with_distance(): void
    {
        $lat = 40.7128;
        $lon = -74.0060;

        Location::factory()->withCoordinates($lat, $lon)->create([
            'organization_id' => $this->organization->id,
            'name' => 'Pizza Place',
            'city' => 'New York',
        ]);

        $results = Location::searchByNameOrCategory('pizza')
            ->withDistance($lat, $lon)
            ->get();

        $this->assertNotEmpty($results);
        $this->assertObjectHasProperty('distance_meters', $results->first());
        $this->assertObjectHasProperty('text_rank', $results->first());
    }

    public function test_scope_chains_with_order_by_distance(): void
    {
        $lat = 40.7128;
        $lon = -74.0060;

        // Create locations at different distances
        Location::factory()->withCoordinates($lat, $lon)->create([
            'organization_id' => $this->organization->id,
            'name' => 'Restaurant Near',
            'city' => 'New York',
        ]);

        Location::factory()->withCoordinates(40.7580, -73.9855)->create([
            'organization_id' => $this->organization->id,
            'name' => 'Restaurant Far',
            'city' => 'New York',
        ]);

        $results = Location::searchByNameOrCategory('restaurant')
            ->withDistance($lat, $lon)
            ->orderByDistance($lat, $lon)
            ->get();

        $this->assertCount(2, $results);
        // First result should be closer
        $this->assertLessThan($results->last()->distance_meters, $results->first()->distance_meters);
    }

    public function test_scope_chains_with_active(): void
    {
        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Active Store',
            'is_active' => true,
        ]);

        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Inactive Store',
            'is_active' => false,
        ]);

        $results = Location::searchByNameOrCategory('store')
            ->active()
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Active Store', $results->first()->name);
        $this->assertTrue($results->first()->is_active);
    }

    public function test_large_result_set_performance(): void
    {
        // Create 100 test locations
        Location::factory()->count(100)->create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Location',
        ]);

        $startTime = microtime(true);

        Location::searchByNameOrCategory('test')->get();

        $executionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

        // Should complete in less than 50ms
        $this->assertLessThan(50, $executionTime, 'Search query took too long: '.$executionTime.'ms');
    }

    public function test_name_field_searched(): void
    {
        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Unique Name Store',
            'address_line_1' => 'Generic Street',
            'city' => 'Generic City',
        ]);

        $results = Location::searchByNameOrCategory('Unique')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Unique Name Store', $results->first()->name);
    }

    public function test_address_line_1_searched(): void
    {
        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Generic Store',
            'address_line_1' => 'Unique Street Address',
            'city' => 'Generic City',
        ]);

        $results = Location::searchByNameOrCategory('Unique Street')->get();

        $this->assertCount(1, $results);
        $this->assertStringContainsString('Unique Street', $results->first()->address_line_1);
    }

    public function test_city_field_searched(): void
    {
        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Generic Store',
            'address_line_1' => 'Generic Street',
            'city' => 'Uniqueville',
        ]);

        $results = Location::searchByNameOrCategory('Uniqueville')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Uniqueville', $results->first()->city);
    }

    public function test_case_insensitive_search(): void
    {
        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Starbucks Coffee',
            'city' => 'Seattle',
        ]);

        // Test different case variations
        $resultsLower = Location::searchByNameOrCategory('starbucks')->get();
        $resultsUpper = Location::searchByNameOrCategory('STARBUCKS')->get();
        $resultsMixed = Location::searchByNameOrCategory('StArBuCkS')->get();

        $this->assertCount(1, $resultsLower);
        $this->assertCount(1, $resultsUpper);
        $this->assertCount(1, $resultsMixed);
    }

    public function test_partial_word_matching(): void
    {
        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'McDonald\'s Restaurant',
            'city' => 'Boston',
        ]);

        // PostgreSQL full-text search uses stemming, so "restaurant" might match "restaurants"
        $results = Location::searchByNameOrCategory('restaurant')->get();

        $this->assertNotEmpty($results);
        $this->assertEquals('McDonald\'s Restaurant', $results->first()->name);
    }

    public function test_multiple_matches_sorted_by_relevance(): void
    {
        // Create locations with varying relevance to "coffee"
        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Coffee Coffee Coffee Shop',
            'address_line_1' => 'Coffee Street',
            'city' => 'Coffee City',
        ]);

        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Tea Shop',
            'address_line_1' => 'Coffee Avenue',
            'city' => 'Boston',
        ]);

        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Generic Store',
            'address_line_1' => 'Main Street',
            'city' => 'Coffee Town',
        ]);

        $results = Location::searchByNameOrCategory('coffee')
            ->orderBy('text_rank', 'desc')
            ->get();

        // First result should have highest text_rank
        $this->assertGreaterThanOrEqual($results->last()->text_rank, $results->first()->text_rank);
    }
}
