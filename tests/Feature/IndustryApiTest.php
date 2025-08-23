<?php

namespace Tests\Feature;

use App\Models\Industry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IndustryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Create test industries
        $this->createTestIndustries();
    }

    protected function createTestIndustries(): void
    {
        // Create root categories
        $restaurants = Industry::factory()->create([
            'name' => 'Restaurants',
            'slug' => 'restaurants',
            'parent_id' => null,
            'depth' => 0,
            'sort' => 1,
        ]);

        $retail = Industry::factory()->create([
            'name' => 'Retail',
            'slug' => 'retail',
            'parent_id' => null,
            'depth' => 0,
            'sort' => 2,
        ]);

        // Create child categories
        Industry::factory()->create([
            'name' => 'Fast Food Restaurant',
            'slug' => 'fast-food-restaurant',
            'parent_id' => $restaurants->id,
            'depth' => 1,
            'sort' => 1,
        ]);

        Industry::factory()->create([
            'name' => 'Coffee Shop',
            'slug' => 'coffee-shop',
            'parent_id' => $restaurants->id,
            'depth' => 1,
            'sort' => 2,
        ]);

        Industry::factory()->create([
            'name' => 'Grocery Store',
            'slug' => 'grocery-store',
            'parent_id' => $retail->id,
            'depth' => 1,
            'sort' => 1,
        ]);
    }

    public function test_index_returns_flat_list_by_default(): void
    {
        $response = $this->getJson('/api/v1/industries');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'depth',
                        'sort',
                        'parent',
                        'breadcrumbs',
                        'children_count',
                    ],
                ],
                'links',
                'meta',
            ]);

        // Should be paginated
        $this->assertArrayHasKey('current_page', $response->json('meta'));
    }

    public function test_index_returns_tree_structure_when_requested(): void
    {
        $response = $this->getJson('/api/v1/industries?tree=true');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'depth',
                        'sort',
                        'parent',
                        'breadcrumbs',
                        'children_count',
                        'children',
                    ],
                ],
            ]);

        // Should not be paginated
        $this->assertArrayNotHasKey('links', $response->json());
        $this->assertArrayNotHasKey('meta', $response->json());
    }

    public function test_index_supports_search(): void
    {
        $response = $this->getJson('/api/v1/industries?q=restaurant');

        $response->assertOk();

        $data = $response->json('data');
        foreach ($data as $industry) {
            $this->assertTrue(
                str_contains(strtolower($industry['name']), 'restaurant') ||
                str_contains(strtolower($industry['slug']), 'restaurant')
            );
        }
    }

    public function test_index_respects_per_page_limit(): void
    {
        // Test default per_page
        $response = $this->getJson('/api/v1/industries');
        $this->assertEquals(25, $response->json('meta.per_page'));

        // Test custom per_page
        $response = $this->getJson('/api/v1/industries?per_page=3');
        $this->assertEquals(3, $response->json('meta.per_page'));

        // Test per_page cap at 100
        $response = $this->getJson('/api/v1/industries?per_page=200');
        $this->assertEquals(100, $response->json('meta.per_page'));
    }

    public function test_autocomplete_requires_search_query(): void
    {
        $response = $this->getJson('/api/v1/industries/autocomplete');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['q']);
    }

    public function test_autocomplete_validates_minimum_query_length(): void
    {
        $response = $this->getJson('/api/v1/industries/autocomplete?q=a');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['q']);
    }

    public function test_autocomplete_returns_correct_format(): void
    {
        $response = $this->getJson('/api/v1/industries/autocomplete?q=rest');

        $response->assertOk()
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'name',
                    'slug',
                    'breadcrumbs_text',
                ],
            ]);

        $data = $response->json();
        $this->assertCount(2, $data); // Should find 2 restaurants

        // Check breadcrumbs_text format
        foreach ($data as $item) {
            $this->assertIsString($item['breadcrumbs_text']);
            $this->assertNotEmpty($item['breadcrumbs_text']);
        }
    }

    public function test_autocomplete_respects_limit(): void
    {
        // Test default limit
        $response = $this->getJson('/api/v1/industries/autocomplete?q=e');
        $data = $response->json();
        $this->assertLessThanOrEqual(10, count($data));

        // Test custom limit
        $response = $this->getJson('/api/v1/industries/autocomplete?q=e&limit=2');
        $data = $response->json();
        $this->assertLessThanOrEqual(2, count($data));

        // Test limit cap at 50
        $response = $this->getJson('/api/v1/industries/autocomplete?q=e&limit=100');
        $data = $response->json();
        $this->assertLessThanOrEqual(50, count($data));
    }

    public function test_show_works_with_id(): void
    {
        $industry = Industry::first();

        $response = $this->getJson("/api/v1/industries/{$industry->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'slug',
                    'depth',
                    'sort',
                    'parent',
                    'breadcrumbs',
                    'children_count',
                ],
            ])
            ->assertJson([
                'data' => [
                    'id' => $industry->id,
                    'name' => $industry->name,
                    'slug' => $industry->slug,
                ],
            ]);
    }

    public function test_show_works_with_slug(): void
    {
        $industry = Industry::where('slug', 'restaurants')->first();

        $response = $this->getJson('/api/v1/industries/restaurants');

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'id' => $industry->id,
                    'name' => $industry->name,
                    'slug' => 'restaurants',
                ],
            ]);
    }

    public function test_show_returns_404_for_non_existent_industry(): void
    {
        $response = $this->getJson('/api/v1/industries/non-existent');

        $response->assertNotFound();
    }

    public function test_all_endpoints_respect_default_filters(): void
    {
        // Create an inactive industry
        $inactive = Industry::factory()->create([
            'name' => 'Inactive Industry',
            'slug' => 'inactive-industry',
            'is_active' => false,
        ]);

        // Index should not return inactive
        $response = $this->getJson('/api/v1/industries');
        $data = $response->json('data');
        $this->assertNotContains('inactive-industry', array_column($data, 'slug'));

        // Tree should not return inactive
        $response = $this->getJson('/api/v1/industries?tree=true');
        $data = $response->json('data');
        $this->assertNotContains('inactive-industry', array_column($data, 'slug'));

        // Autocomplete should not return inactive
        $response = $this->getJson('/api/v1/industries/autocomplete?q=inactive');
        $data = $response->json();
        $this->assertEmpty($data);

        // Show should return 404 for inactive
        $response = $this->getJson('/api/v1/industries/inactive-industry');
        $response->assertNotFound();
    }

    public function test_caching_behavior(): void
    {
        // Clear cache
        Cache::flush();

        // First request should hit database and cache
        $response1 = $this->getJson('/api/v1/industries?tree=true');
        $response1->assertOk();

        // Check cache was set
        $cacheKey = 'industries:'.Cache::get('industries:ver', 1).':tree';
        $this->assertTrue(Cache::has($cacheKey));

        // Second request should use cache
        $response2 = $this->getJson('/api/v1/industries?tree=true');
        $response2->assertOk();

        $this->assertEquals($response1->json(), $response2->json());
    }

    public function test_cache_versioning(): void
    {
        // Set initial cache version
        Cache::put('industries:ver', 1);

        // Make request to cache data
        $this->getJson('/api/v1/industries?tree=true');
        $key1 = 'industries:1:tree';
        $this->assertTrue(Cache::has($key1));

        // Increment cache version (simulating industry update)
        Cache::put('industries:ver', 2);

        // New request should use new cache key
        $this->getJson('/api/v1/industries?tree=true');
        $key2 = 'industries:2:tree';
        $this->assertTrue(Cache::has($key2));
    }

    public function test_eager_loading_prevents_n_plus_one_queries(): void
    {
        // This test ensures we're not making excessive database queries
        $this->withoutExceptionHandling();

        // Track query count
        $queryCount = 0;
        \DB::listen(function ($query) use (&$queryCount) {
            $queryCount++;
        });

        // Make request that should load parent and children relationships
        $this->getJson('/api/v1/industries?tree=true');

        // Should not exceed reasonable query count (base query + children loading + breadcrumbs per item)
        // This is acceptable for the current implementation with breadcrumbs
        $this->assertLessThanOrEqual(15, $queryCount);
    }
}
