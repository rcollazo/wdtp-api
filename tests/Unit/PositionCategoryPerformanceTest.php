<?php

namespace Tests\Unit;

use App\Models\Industry;
use App\Models\PositionCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PositionCategoryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test industries
        $this->restaurant = Industry::factory()->create(['name' => 'Restaurants']);
        $this->retail = Industry::factory()->create(['name' => 'Retail']);
        $this->healthcare = Industry::factory()->create(['name' => 'Healthcare']);
    }

    public function test_query_count_for_positions_with_industries(): void
    {
        // Create multiple positions across industries
        PositionCategory::factory()->count(5)->create(['industry_id' => $this->restaurant->id]);
        PositionCategory::factory()->count(5)->create(['industry_id' => $this->retail->id]);
        PositionCategory::factory()->count(5)->create(['industry_id' => $this->healthcare->id]);

        // Track queries
        $queryCount = 0;
        DB::listen(function ($query) use (&$queryCount) {
            $queryCount++;
        });

        // Fetch positions with industries - should use eager loading
        $positions = PositionCategory::with('industry')->get();

        // Should be 1 query for positions + 1 query for industries
        $this->assertLessThanOrEqual(2, $queryCount);
        $this->assertCount(15, $positions);

        // Verify relationships are loaded
        $positions->each(function ($position) {
            $this->assertTrue($position->relationLoaded('industry'));
            $this->assertNotNull($position->industry);
        });
    }

    public function test_search_performance_with_large_dataset(): void
    {
        // Create a larger dataset with unique entries
        for ($i = 0; $i < 25; $i++) {
            PositionCategory::factory()->create([
                'name' => "Restaurant Position {$i}",
                'slug' => "restaurant-position-{$i}",
                'industry_id' => $this->restaurant->id,
            ]);
        }

        for ($i = 0; $i < 25; $i++) {
            PositionCategory::factory()->create([
                'name' => "Retail Position {$i}",
                'slug' => "retail-position-{$i}",
                'industry_id' => $this->retail->id,
            ]);
        }

        $startTime = microtime(true);

        // Perform search query
        $results = PositionCategory::search('position')->get();

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // Query should complete within reasonable time (1 second)
        $this->assertLessThan(1.0, $executionTime);
    }

    public function test_industry_filtering_performance(): void
    {
        // Create positions across industries with unique data
        for ($i = 0; $i < 15; $i++) {
            PositionCategory::factory()->create([
                'name' => "Restaurant Filter Position {$i}",
                'slug' => "restaurant-filter-position-{$i}",
                'industry_id' => $this->restaurant->id,
            ]);
        }

        for ($i = 0; $i < 15; $i++) {
            PositionCategory::factory()->create([
                'name' => "Retail Filter Position {$i}",
                'slug' => "retail-filter-position-{$i}",
                'industry_id' => $this->retail->id,
            ]);
        }

        for ($i = 0; $i < 15; $i++) {
            PositionCategory::factory()->create([
                'name' => "Healthcare Filter Position {$i}",
                'slug' => "healthcare-filter-position-{$i}",
                'industry_id' => $this->healthcare->id,
            ]);
        }

        $queryCount = 0;
        DB::listen(function ($query) use (&$queryCount) {
            $queryCount++;
        });

        $startTime = microtime(true);

        // Filter by industry - should use index
        $results = PositionCategory::forIndustry($this->restaurant->id)->get();

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        $this->assertCount(15, $results);
        $this->assertLessThan(0.5, $executionTime); // Should be fast due to index
        $this->assertLessThanOrEqual(2, $queryCount); // Should be efficient query count
    }

    public function test_combined_scopes_performance(): void
    {
        // Create mixed status positions
        PositionCategory::factory()->count(20)->active()->create(['industry_id' => $this->restaurant->id]);
        PositionCategory::factory()->count(10)->inactive()->create(['industry_id' => $this->restaurant->id]);
        PositionCategory::factory()->count(15)->active()->create(['industry_id' => $this->retail->id]);

        $queryCount = 0;
        DB::listen(function ($query) use (&$queryCount) {
            $queryCount++;
        });

        $startTime = microtime(true);

        // Combine multiple scopes
        $results = PositionCategory::active()
            ->forIndustry($this->restaurant->id)
            ->get();

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        $this->assertCount(20, $results);
        $this->assertLessThan(0.5, $executionTime);
        $this->assertLessThanOrEqual(2, $queryCount); // Should use composite index
    }

    public function test_route_binding_performance(): void
    {
        $position = PositionCategory::factory()->create([
            'name' => 'Test Position',
            'slug' => 'test-position',
            'industry_id' => $this->restaurant->id,
        ]);

        $queryCount = 0;
        DB::listen(function ($query) use (&$queryCount) {
            $queryCount++;
        });

        $startTime = microtime(true);

        // Test ID-based resolution
        $resolved = (new PositionCategory)->resolveRouteBinding($position->id);

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        $this->assertInstanceOf(PositionCategory::class, $resolved);
        $this->assertEquals($position->id, $resolved->id);
        $this->assertLessThan(0.1, $executionTime); // Should be very fast with primary key
        $this->assertEquals(1, $queryCount); // Should be exactly 1 query

        // Reset counters
        $queryCount = 0;

        $startTime = microtime(true);

        // Test slug-based resolution
        $resolved = (new PositionCategory)->resolveRouteBinding('test-position');

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        $this->assertInstanceOf(PositionCategory::class, $resolved);
        $this->assertEquals($position->id, $resolved->id);
        $this->assertLessThan(0.2, $executionTime); // Should be fast with unique index
        $this->assertLessThanOrEqual(2, $queryCount); // At most 2 queries (try ID first, then slug)
    }

    public function test_bulk_operations_performance(): void
    {
        $startTime = microtime(true);

        // Bulk create positions
        $positions = collect();
        for ($i = 0; $i < 100; $i++) {
            $positions->push([
                'name' => "Position {$i}",
                'slug' => "position-{$i}",
                'description' => "Description for position {$i}",
                'industry_id' => $this->restaurant->id,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        PositionCategory::insert($positions->toArray());

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        $this->assertLessThan(1.0, $executionTime); // Bulk insert should be fast
        $this->assertEquals(100, PositionCategory::count());
    }

    public function test_pagination_performance_with_joins(): void
    {
        // Create a decent dataset
        PositionCategory::factory()->count(50)->create(['industry_id' => $this->restaurant->id]);
        PositionCategory::factory()->count(50)->create(['industry_id' => $this->retail->id]);

        $queryCount = 0;
        DB::listen(function ($query) use (&$queryCount) {
            $queryCount++;
        });

        $startTime = microtime(true);

        // Paginate with join (similar to controller index method)
        $results = PositionCategory::query()
            ->with(['industry'])
            ->leftJoin('industries', 'position_categories.industry_id', '=', 'industries.id')
            ->orderBy('industries.name')
            ->orderBy('position_categories.name')
            ->select('position_categories.*')
            ->paginate(25);

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        $this->assertLessThan(0.5, $executionTime); // Should be reasonably fast
        $this->assertLessThanOrEqual(5, $queryCount); // Pagination + eager loading queries
        $this->assertEquals(25, $results->count());
        $this->assertEquals(100, $results->total());
    }

    public function test_memory_usage_for_large_collections(): void
    {
        // Create a larger dataset
        PositionCategory::factory()->count(200)->create(['industry_id' => $this->restaurant->id]);

        $memoryBefore = memory_get_usage();

        // Load all positions with relationships
        $positions = PositionCategory::with('industry')->get();

        $memoryAfter = memory_get_usage();
        $memoryUsed = $memoryAfter - $memoryBefore;

        $this->assertCount(200, $positions);

        // Memory usage should be reasonable (less than 10MB for 200 records)
        $this->assertLessThan(10 * 1024 * 1024, $memoryUsed);
    }

    public function test_concurrent_slug_generation_performance(): void
    {
        $startTime = microtime(true);

        // Simulate creation of positions with unique names to avoid conflicts
        $positions = [];
        for ($i = 0; $i < 25; $i++) {
            $positions[] = PositionCategory::create([
                'name' => "Server Position {$i}",
                'slug' => "server-position-{$i}",
                'description' => "Description {$i}",
                'industry_id' => $this->restaurant->id,
                'status' => 'active',
            ]);
        }

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // Should complete within reasonable time
        $this->assertLessThan(3.0, $executionTime);

        // Verify all slugs are unique
        $slugs = collect($positions)->pluck('slug')->toArray();
        $this->assertEquals(count($slugs), count(array_unique($slugs)));
    }
}
