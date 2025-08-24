<?php

namespace Tests\Feature;

use App\Models\Industry;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    private Industry $industry1;

    private Industry $industry2;

    private Organization $org1;

    private Organization $org2;

    private Organization $org3;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test industries
        $this->industry1 = Industry::factory()->create([
            'name' => 'Food Service',
            'slug' => 'food-service',
        ]);

        $this->industry2 = Industry::factory()->create([
            'name' => 'Retail',
            'slug' => 'retail',
        ]);

        // Create test organizations
        $this->org1 = Organization::factory()->create([
            'name' => 'Starbucks Corporation',
            'slug' => 'starbucks',
            'domain' => 'starbucks.com',
            'legal_name' => 'Starbucks Corporation',
            'website_url' => 'https://starbucks.com',
            'description' => 'Global coffeehouse chain',
            'primary_industry_id' => $this->industry1->id,
            'is_active' => true,
            'visible_in_ui' => true,
            'status' => 'active',
            'verification_status' => 'verified',
            'locations_count' => 100,
            'wage_reports_count' => 50,
            'verified_at' => now(),
        ]);

        $this->org2 = Organization::factory()->create([
            'name' => 'Target',
            'slug' => 'target',
            'domain' => 'target.com',
            'primary_industry_id' => $this->industry2->id,
            'is_active' => true,
            'visible_in_ui' => true,
            'status' => 'active',
            'verification_status' => 'pending',
            'locations_count' => 50,
            'wage_reports_count' => 25,
        ]);

        // Inactive organization - should not appear in results
        $this->org3 = Organization::factory()->create([
            'name' => 'Hidden Corp',
            'slug' => 'hidden-corp',
            'domain' => 'hidden-corp.com',
            'is_active' => false,
            'visible_in_ui' => false,
            'status' => 'inactive',
        ]);

        // Clear cache before each test
        Cache::flush();
    }

    public function test_index_returns_paginated_organizations(): void
    {
        $response = $this->getJson('/api/v1/organizations');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'domain',
                        'primary_industry',
                        'locations_count',
                        'wage_reports_count',
                        'is_verified',
                    ],
                ],
                'links',
                'meta',
            ])
            ->assertJsonCount(2, 'data'); // Only active/visible organizations

        // Check that inactive organization is not included
        $response->assertJsonMissing(['name' => 'Hidden Corp']);
    }

    public function test_index_applies_default_filters(): void
    {
        // All organizations should be active, visible, and approved (status=active)
        $response = $this->getJson('/api/v1/organizations');

        $response->assertOk();

        $data = $response->json('data');
        foreach ($data as $org) {
            $this->assertNotEquals('Hidden Corp', $org['name']);
        }
    }

    public function test_index_search_functionality(): void
    {
        $response = $this->getJson('/api/v1/organizations?q=starbucks');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Starbucks Corporation']);
    }

    public function test_index_search_by_domain(): void
    {
        $response = $this->getJson('/api/v1/organizations?q=target.com');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Target']);
    }

    public function test_index_filter_by_industry_id(): void
    {
        $response = $this->getJson('/api/v1/organizations?industry_id='.$this->industry1->id);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Starbucks Corporation']);
    }

    public function test_index_filter_by_industry_slug(): void
    {
        $response = $this->getJson('/api/v1/organizations?industry_slug=retail');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Target']);
    }

    public function test_index_filter_by_verified_status(): void
    {
        $response = $this->getJson('/api/v1/organizations?verified=true');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Starbucks Corporation']);

        $response = $this->getJson('/api/v1/organizations?verified=false');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Target']);
    }

    public function test_index_filter_by_has_locations(): void
    {
        // Create org with no locations
        Organization::factory()->create([
            'name' => 'No Locations Corp',
            'slug' => 'no-locations',
            'primary_industry_id' => $this->industry1->id,
            'is_active' => true,
            'visible_in_ui' => true,
            'status' => 'active',
            'locations_count' => 0,
        ]);

        $response = $this->getJson('/api/v1/organizations?has_locations=true');

        $response->assertOk()
            ->assertJsonCount(2, 'data'); // Only orgs with locations

        $response = $this->getJson('/api/v1/organizations?has_locations=false');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'No Locations Corp']);
    }

    public function test_index_sorting_by_name(): void
    {
        $response = $this->getJson('/api/v1/organizations?sort=name');

        $response->assertOk();

        $data = $response->json('data');
        $names = array_column($data, 'name');

        $this->assertEquals(['Starbucks Corporation', 'Target'], $names);
    }

    public function test_index_sorting_by_locations(): void
    {
        $response = $this->getJson('/api/v1/organizations?sort=locations');

        $response->assertOk();

        $data = $response->json('data');
        $locationCounts = array_column($data, 'locations_count');

        // Should be sorted by locations desc (100, 50)
        $this->assertEquals([100, 50], $locationCounts);
    }

    public function test_index_sorting_by_wage_reports(): void
    {
        $response = $this->getJson('/api/v1/organizations?sort=wage_reports');

        $response->assertOk();

        $data = $response->json('data');
        $reportCounts = array_column($data, 'wage_reports_count');

        // Should be sorted by wage_reports desc (50, 25)
        $this->assertEquals([50, 25], $reportCounts);
    }

    public function test_index_default_sort_with_search(): void
    {
        // When searching, default sort should be relevance
        $response = $this->getJson('/api/v1/organizations?q=star');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Starbucks Corporation']);
    }

    public function test_index_pagination(): void
    {
        // Create more organizations with unique names to avoid constraint violations
        for ($i = 1; $i <= 10; $i++) {
            Organization::factory()->create([
                'name' => "Test Organization {$i}",
                'slug' => "test-organization-{$i}",
                'legal_name' => "Test Organization {$i} Corp",
                'domain' => "test-org-{$i}.com",
                'website_url' => "https://test-org-{$i}.com",
                'primary_industry_id' => $this->industry1->id,
                'is_active' => true,
                'visible_in_ui' => true,
                'status' => 'active',
            ]);
        }

        $response = $this->getJson('/api/v1/organizations?per_page=5');

        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure([
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'total', 'per_page'],
            ]);
    }

    public function test_index_validation_errors(): void
    {
        $response = $this->getJson('/api/v1/organizations?q=a'); // Too short

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);

        $response = $this->getJson('/api/v1/organizations?per_page=101'); // Too large

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);

        $response = $this->getJson('/api/v1/organizations?sort=invalid'); // Invalid sort

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['sort']);

        $response = $this->getJson('/api/v1/organizations?industry_id=999'); // Non-existent industry

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['industry_id']);
    }

    public function test_show_returns_organization_by_id(): void
    {
        $response = $this->getJson('/api/v1/organizations/'.$this->org1->id);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'slug',
                    'domain',
                    'legal_name',
                    'website_url',
                    'description',
                    'logo_url',
                    'primary_industry',
                    'locations_count',
                    'wage_reports_count',
                    'is_verified',
                    'verified_at',
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJsonFragment(['name' => 'Starbucks Corporation']);
    }

    public function test_show_returns_organization_by_slug(): void
    {
        $response = $this->getJson('/api/v1/organizations/starbucks');

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Starbucks Corporation']);
    }

    public function test_show_includes_primary_industry_relationship(): void
    {
        $response = $this->getJson('/api/v1/organizations/'.$this->org1->id);

        $response->assertOk()
            ->assertJsonFragment([
                'primary_industry' => [
                    'id' => $this->industry1->id,
                    'name' => $this->industry1->name,
                    'slug' => $this->industry1->slug,
                ],
            ]);
    }

    public function test_show_applies_default_filters(): void
    {
        // Try to access inactive organization
        $response = $this->getJson('/api/v1/organizations/'.$this->org3->id);

        $response->assertNotFound();
    }

    public function test_show_returns_404_for_non_existent_organization(): void
    {
        $response = $this->getJson('/api/v1/organizations/999');

        $response->assertNotFound();

        $response = $this->getJson('/api/v1/organizations/non-existent-slug');

        $response->assertNotFound();
    }

    public function test_index_caching(): void
    {
        // Clear cache to ensure clean test
        Cache::flush();

        // Make first request
        $response1 = $this->getJson('/api/v1/organizations');
        $response1->assertOk();

        // Cache should now contain the result - match controller boolean handling
        $expectedCacheKey = 'orgs:1:index:'.md5(json_encode([
            'q' => null,
            'industry_id' => null,
            'industry_slug' => null,
            'verified' => false, // boolean() returns false when not provided
            'has_locations' => false, // boolean() returns false when not provided
            'per_page' => 25,
            'sort' => null,
        ]));

        $this->assertTrue(Cache::has($expectedCacheKey));

        // Make second identical request - should come from cache
        $response2 = $this->getJson('/api/v1/organizations');
        $response2->assertOk();

        $this->assertEquals($response1->json(), $response2->json());
    }

    public function test_show_caching(): void
    {
        // Clear cache
        Cache::flush();

        // Make first request
        $response1 = $this->getJson('/api/v1/organizations/'.$this->org1->id);
        $response1->assertOk();

        // Cache should contain the result
        $cacheKey = 'orgs:1:show:'.$this->org1->id;
        $this->assertTrue(Cache::has($cacheKey));

        // Make second request - should come from cache
        $response2 = $this->getJson('/api/v1/organizations/'.$this->org1->id);
        $response2->assertOk();

        $this->assertEquals($response1->json(), $response2->json());
    }

    public function test_slug_resolution_caching(): void
    {
        // Clear cache
        Cache::flush();

        $response = $this->getJson('/api/v1/organizations/'.$this->org1->slug);

        $response->assertOk();

        // Cache should exist for slug-based lookup
        $cacheKey = 'orgs:1:show:'.$this->org1->slug;
        $this->assertTrue(Cache::has($cacheKey));
    }

    public function test_cache_version_system(): void
    {
        // Test that cache version can be retrieved
        $version = Cache::get('orgs:ver', 1);
        $this->assertIsInt($version);
    }
}
