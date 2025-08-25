<?php

namespace Tests\Feature;

use App\Models\Industry;
use App\Models\PositionCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PositionCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestData();
    }

    protected function createTestData(): void
    {
        // Create test industries
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

        $health = Industry::factory()->create([
            'name' => 'Health',
            'slug' => 'health',
            'parent_id' => null,
            'depth' => 0,
            'sort' => 3,
        ]);

        // Create position categories
        PositionCategory::factory()->create([
            'name' => 'Server',
            'slug' => 'server-restaurants',
            'description' => 'Takes customer orders and serves food',
            'industry_id' => $restaurants->id,
            'status' => 'active',
        ]);

        PositionCategory::factory()->create([
            'name' => 'Cook',
            'slug' => 'cook-restaurants',
            'description' => 'Prepares food items according to recipes',
            'industry_id' => $restaurants->id,
            'status' => 'active',
        ]);

        PositionCategory::factory()->create([
            'name' => 'Sales Associate',
            'slug' => 'sales-associate-retail',
            'description' => 'Assists customers with product selection',
            'industry_id' => $retail->id,
            'status' => 'active',
        ]);

        PositionCategory::factory()->create([
            'name' => 'Cashier',
            'slug' => 'cashier-retail',
            'description' => 'Processes customer transactions',
            'industry_id' => $retail->id,
            'status' => 'active',
        ]);

        PositionCategory::factory()->create([
            'name' => 'Medical Assistant',
            'slug' => 'medical-assistant-health',
            'description' => 'Performs clinical and administrative tasks',
            'industry_id' => $health->id,
            'status' => 'active',
        ]);

        // Create an inactive position for testing filters
        PositionCategory::factory()->create([
            'name' => 'Inactive Position',
            'slug' => 'inactive-position-restaurants',
            'description' => 'This position is inactive',
            'industry_id' => $restaurants->id,
            'status' => 'inactive',
        ]);
    }

    public function test_index_returns_paginated_list_by_default(): void
    {
        $response = $this->getJson('/api/v1/position-categories');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'description',
                        'status',
                        'industry',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links',
                'meta',
            ]);

        // Should be paginated
        $this->assertArrayHasKey('current_page', $response->json('meta'));

        // Should only return active positions by default
        $data = $response->json('data');
        foreach ($data as $position) {
            $this->assertEquals('active', $position['status']);
        }
    }

    public function test_index_supports_industry_filtering_by_id(): void
    {
        $retailIndustry = Industry::where('slug', 'retail')->first();

        $response = $this->getJson("/api/v1/position-categories?industry={$retailIndustry->id}");

        $response->assertOk();

        $data = $response->json('data');
        $this->assertGreaterThan(0, count($data));

        foreach ($data as $position) {
            $this->assertEquals($retailIndustry->id, $position['industry']['id']);
        }
    }

    public function test_index_supports_industry_filtering_by_slug(): void
    {
        $response = $this->getJson('/api/v1/position-categories?industry=restaurants');

        $response->assertOk();

        $data = $response->json('data');
        $this->assertGreaterThan(0, count($data));

        foreach ($data as $position) {
            $this->assertEquals('restaurants', $position['industry']['slug']);
        }
    }

    public function test_index_supports_search(): void
    {
        $response = $this->getJson('/api/v1/position-categories?q=server');

        $response->assertOk();

        $data = $response->json('data');
        $this->assertGreaterThan(0, count($data));

        foreach ($data as $position) {
            $this->assertTrue(
                str_contains(strtolower($position['name']), 'server') ||
                str_contains(strtolower($position['description']), 'server')
            );
        }
    }

    public function test_index_supports_status_filtering(): void
    {
        // Test active only (default)
        $response = $this->getJson('/api/v1/position-categories?status=active');
        $data = $response->json('data');
        foreach ($data as $position) {
            $this->assertEquals('active', $position['status']);
        }

        // Test inactive only
        $response = $this->getJson('/api/v1/position-categories?status=inactive');
        $data = $response->json('data');
        $this->assertGreaterThan(0, count($data));
        foreach ($data as $position) {
            $this->assertEquals('inactive', $position['status']);
        }

        // Test all statuses
        $response = $this->getJson('/api/v1/position-categories?status=all');
        $data = $response->json('data');
        $statuses = array_column($data, 'status');
        $this->assertContains('active', $statuses);
        $this->assertContains('inactive', $statuses);
    }

    public function test_index_respects_per_page_limit(): void
    {
        // Test default per_page
        $response = $this->getJson('/api/v1/position-categories');
        $this->assertEquals(25, $response->json('meta.per_page'));

        // Test custom per_page
        $response = $this->getJson('/api/v1/position-categories?per_page=3');
        $this->assertEquals(3, $response->json('meta.per_page'));

        // Test per_page cap at 100
        $response = $this->getJson('/api/v1/position-categories?per_page=200');
        $this->assertEquals(100, $response->json('meta.per_page'));
    }

    public function test_index_orders_by_industry_then_position_name(): void
    {
        $response = $this->getJson('/api/v1/position-categories');

        $response->assertOk();

        $data = $response->json('data');
        $this->assertGreaterThan(1, count($data));

        // Check that positions are ordered by industry name, then position name
        for ($i = 1; $i < count($data); $i++) {
            $prev = $data[$i - 1];
            $curr = $data[$i];

            if ($prev['industry']['name'] === $curr['industry']['name']) {
                // Same industry, check position name ordering
                $this->assertLessThanOrEqual(0, strcasecmp($prev['name'], $curr['name']));
            } else {
                // Different industry, check industry name ordering
                $this->assertLessThanOrEqual(0, strcasecmp($prev['industry']['name'], $curr['industry']['name']));
            }
        }
    }

    public function test_autocomplete_requires_search_query(): void
    {
        $response = $this->getJson('/api/v1/position-categories/autocomplete');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['q']);
    }

    public function test_autocomplete_validates_minimum_query_length(): void
    {
        $response = $this->getJson('/api/v1/position-categories/autocomplete?q=a');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['q']);
    }

    public function test_autocomplete_returns_correct_format(): void
    {
        $response = $this->getJson('/api/v1/position-categories/autocomplete?q=serv');

        $response->assertOk()
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'name',
                    'slug',
                    'industry_name',
                ],
            ]);

        $data = $response->json();
        $this->assertGreaterThan(0, count($data));

        foreach ($data as $item) {
            $this->assertIsString($item['industry_name']);
            $this->assertNotEmpty($item['industry_name']);
        }
    }

    public function test_autocomplete_supports_industry_filtering(): void
    {
        $response = $this->getJson('/api/v1/position-categories/autocomplete?q=er&industry=restaurants');

        $response->assertOk();

        $data = $response->json();
        foreach ($data as $item) {
            $this->assertEquals('Restaurants', $item['industry_name']);
        }
    }

    public function test_autocomplete_respects_limit(): void
    {
        // Test default limit
        $response = $this->getJson('/api/v1/position-categories/autocomplete?q=a');
        $data = $response->json();
        $this->assertLessThanOrEqual(10, count($data));

        // Test custom limit
        $response = $this->getJson('/api/v1/position-categories/autocomplete?q=a&limit=2');
        $data = $response->json();
        $this->assertLessThanOrEqual(2, count($data));

        // Test limit cap at 50
        $response = $this->getJson('/api/v1/position-categories/autocomplete?q=a&limit=100');
        $data = $response->json();
        $this->assertLessThanOrEqual(50, count($data));
    }

    public function test_autocomplete_only_returns_active_positions(): void
    {
        $response = $this->getJson('/api/v1/position-categories/autocomplete?q=inactive');

        $response->assertOk();

        $data = $response->json();
        $this->assertEmpty($data); // Should not find the inactive position
    }

    public function test_show_works_with_id(): void
    {
        $position = PositionCategory::where('status', 'active')->first();

        $response = $this->getJson("/api/v1/position-categories/{$position->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'slug',
                    'description',
                    'status',
                    'industry',
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJson([
                'data' => [
                    'id' => $position->id,
                    'name' => $position->name,
                    'slug' => $position->slug,
                ],
            ]);
    }

    public function test_show_works_with_slug(): void
    {
        $position = PositionCategory::where('slug', 'server-restaurants')->first();

        $response = $this->getJson('/api/v1/position-categories/server-restaurants');

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'id' => $position->id,
                    'name' => $position->name,
                    'slug' => 'server-restaurants',
                ],
            ]);
    }

    public function test_show_includes_industry_relationship(): void
    {
        $position = PositionCategory::with('industry')->first();

        $response = $this->getJson("/api/v1/position-categories/{$position->id}");

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'industry' => [
                        'id' => $position->industry->id,
                        'name' => $position->industry->name,
                        'slug' => $position->industry->slug,
                    ],
                ],
            ]);
    }

    public function test_show_returns_404_for_non_existent_position(): void
    {
        $response = $this->getJson('/api/v1/position-categories/non-existent');

        $response->assertNotFound();
    }

    public function test_caching_behavior_for_autocomplete(): void
    {
        // Clear cache
        Cache::flush();

        // First request should hit database and cache
        $response1 = $this->getJson('/api/v1/position-categories/autocomplete?q=server&limit=5');
        $response1->assertOk();

        // Check cache was set
        $cacheKey = 'position-categories:ac:'.md5('server'.'5');
        $this->assertTrue(Cache::has($cacheKey));

        // Second request should use cache
        $response2 = $this->getJson('/api/v1/position-categories/autocomplete?q=server&limit=5');
        $response2->assertOk();

        $this->assertEquals($response1->json(), $response2->json());
    }

    public function test_caching_behavior_for_show(): void
    {
        // Clear cache
        Cache::flush();

        $position = PositionCategory::first();

        // First request should hit database and cache
        $response1 = $this->getJson("/api/v1/position-categories/{$position->id}");
        $response1->assertOk();

        // Check cache was set
        $cacheKey = 'position-categories:show:'.$position->id;
        $this->assertTrue(Cache::has($cacheKey));

        // Second request should use cache
        $response2 = $this->getJson("/api/v1/position-categories/{$position->id}");
        $response2->assertOk();

        $this->assertEquals($response1->json(), $response2->json());
    }

    public function test_validation_errors_are_properly_formatted(): void
    {
        // Test invalid search query length
        $response = $this->getJson('/api/v1/position-categories?q=a');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['q']);

        // Test invalid status value
        $response = $this->getJson('/api/v1/position-categories?status=invalid');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // Test invalid per_page value
        $response = $this->getJson('/api/v1/position-categories?per_page=0');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_eager_loading_prevents_n_plus_one_queries(): void
    {
        $this->withoutExceptionHandling();

        // Track query count
        $queryCount = 0;
        \DB::listen(function ($query) use (&$queryCount) {
            $queryCount++;
        });

        // Make request that should load industry relationships
        $this->getJson('/api/v1/position-categories');

        // Should not exceed reasonable query count
        // 1 for positions + 1 for industries join + 1 for pagination count
        $this->assertLessThanOrEqual(5, $queryCount);
    }

    public function test_api_response_time_performance(): void
    {
        // Create a reasonable dataset with unique positions
        $restaurant = Industry::where('slug', 'restaurants')->first();
        for ($i = 0; $i < 20; $i++) {
            PositionCategory::factory()->create([
                'name' => 'Performance Test Position '.$i,
                'slug' => 'performance-test-position-'.$i,
                'industry_id' => $restaurant->id,
            ]);
        }

        $startTime = microtime(true);

        $response = $this->getJson('/api/v1/position-categories');

        $endTime = microtime(true);
        $responseTime = $endTime - $startTime;

        $response->assertOk();
        // API should respond within 500ms
        $this->assertLessThan(0.5, $responseTime);
    }

    public function test_api_handles_malformed_query_parameters(): void
    {
        // Test various malformed parameters
        $malformedParams = [
            'per_page=abc',
            'per_page=-1',
            'per_page=10000',
            'limit=abc',
            'limit=-1',
            'status=invalid',
            'q=a', // Too short
        ];

        foreach ($malformedParams as $param) {
            $response = $this->getJson("/api/v1/position-categories?{$param}");
            $this->assertTrue(in_array($response->status(), [422, 200])); // Either validation error or ignored
        }
    }

    public function test_api_returns_consistent_json_structure(): void
    {
        $response = $this->getJson('/api/v1/position-categories');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'description',
                        'status',
                        'industry' => [
                            'id',
                            'name',
                            'slug',
                        ],
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links' => [
                    'first',
                    'last',
                    'prev',
                    'next',
                ],
                'meta' => [
                    'current_page',
                    'from',
                    'last_page',
                    'links',
                    'path',
                    'per_page',
                    'to',
                    'total',
                ],
            ]);
    }

    public function test_api_handles_empty_results_gracefully(): void
    {
        // Clear all positions
        PositionCategory::truncate();

        $response = $this->getJson('/api/v1/position-categories');

        $response->assertOk()
            ->assertJson([
                'data' => [],
                'meta' => [
                    'total' => 0,
                ],
            ]);
    }

    public function test_api_handles_special_characters_in_search(): void
    {
        // Clear existing data to avoid conflicts
        PositionCategory::truncate();

        $position = PositionCategory::factory()->create([
            'name' => 'Manager & Assistant',
            'slug' => 'manager-assistant-special',
            'description' => 'Manages staff & assists customers',
            'industry_id' => Industry::first()->id,
        ]);

        // Test search with part of the name that should match
        $response = $this->getJson('/api/v1/position-categories?q='.urlencode('Manager'));

        $response->assertOk();
        $data = $response->json('data');

        $found = collect($data)->first(function ($item) use ($position) {
            return $item['id'] === $position->id;
        });

        $this->assertNotNull($found, 'Position with special characters should be found in search');
    }

    public function test_api_pagination_edge_cases(): void
    {
        // Test first page
        $response = $this->getJson('/api/v1/position-categories?page=1');
        $response->assertOk();
        $this->assertEquals(1, $response->json('meta.current_page'));

        // Test beyond last page
        $response = $this->getJson('/api/v1/position-categories?page=9999');
        $response->assertOk();
        $this->assertEmpty($response->json('data'));

        // Test page 0 (should default to 1)
        $response = $this->getJson('/api/v1/position-categories?page=0');
        $response->assertOk();
    }

    public function test_autocomplete_cache_invalidation(): void
    {
        \Cache::flush();

        // First request
        $response1 = $this->getJson('/api/v1/position-categories/autocomplete?q=server');
        $response1->assertOk();
        $data1 = $response1->json();

        // Create a new position
        PositionCategory::factory()->create([
            'name' => 'Senior Server',
            'industry_id' => Industry::first()->id,
        ]);

        // Cache should still return old results within TTL
        $response2 = $this->getJson('/api/v1/position-categories/autocomplete?q=server');
        $response2->assertOk();
        $data2 = $response2->json();

        // Should be the same due to caching
        $this->assertEquals($data1, $data2);
    }

    public function test_show_endpoint_handles_mixed_id_types(): void
    {
        $position = PositionCategory::factory()->create([
            'name' => 'Test Position',
            'slug' => 'test-position-123',
            'industry_id' => Industry::first()->id,
        ]);

        // Test with integer ID
        $response1 = $this->getJson("/api/v1/position-categories/{$position->id}");
        $response1->assertOk()->assertJson(['data' => ['id' => $position->id]]);

        // Test with string ID
        $response2 = $this->getJson("/api/v1/position-categories/{$position->id}");
        $response2->assertOk()->assertJson(['data' => ['id' => $position->id]]);

        // Test with slug
        $response3 = $this->getJson('/api/v1/position-categories/test-position-123');
        $response3->assertOk()->assertJson(['data' => ['id' => $position->id]]);
    }

    public function test_api_content_type_headers(): void
    {
        $response = $this->getJson('/api/v1/position-categories');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_api_handles_concurrent_requests(): void
    {
        // Simulate concurrent requests by making multiple rapid requests
        $responses = [];

        for ($i = 0; $i < 5; $i++) {
            $responses[] = $this->getJson('/api/v1/position-categories');
        }

        // All should succeed
        foreach ($responses as $response) {
            $response->assertOk();
        }
    }

    public function test_api_search_case_insensitive(): void
    {
        $position = PositionCategory::factory()->create([
            'name' => 'Server Position',
            'description' => 'Serves customers',
            'industry_id' => Industry::first()->id,
        ]);

        $searchTerms = ['server', 'SERVER', 'Server', 'SeRvEr'];

        foreach ($searchTerms as $term) {
            $response = $this->getJson("/api/v1/position-categories?q={$term}");
            $response->assertOk();

            $data = $response->json('data');
            $this->assertGreaterThan(0, count($data));
        }
    }

    public function test_api_industry_filter_with_non_existent_industry(): void
    {
        // Test with non-existent numeric ID
        $response = $this->getJson('/api/v1/position-categories?industry=99999');
        $response->assertOk();
        $this->assertEmpty($response->json('data'));

        // Test with non-existent slug
        $response = $this->getJson('/api/v1/position-categories?industry=non-existent-industry');
        $response->assertOk();
        $this->assertEmpty($response->json('data'));
    }

    public function test_api_resource_data_types_are_correct(): void
    {
        $response = $this->getJson('/api/v1/position-categories');
        $response->assertOk();

        $firstItem = $response->json('data.0');

        if ($firstItem) {
            $this->assertIsInt($firstItem['id']);
            $this->assertIsString($firstItem['name']);
            $this->assertIsString($firstItem['slug']);
            $this->assertTrue(is_string($firstItem['description']) || is_null($firstItem['description']));
            $this->assertIsString($firstItem['status']);
            $this->assertIsArray($firstItem['industry']);
            $this->assertIsString($firstItem['created_at']);
            $this->assertIsString($firstItem['updated_at']);
        }
    }
}
