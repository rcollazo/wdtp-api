<?php

namespace Tests\Unit;

use App\Http\Resources\IndustryNodeResource;
use App\Http\Resources\IndustryResource;
use App\Models\Industry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class IndustryResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test IndustryResource returns correct fields and excludes internal fields.
     */
    public function test_industry_resource_returns_correct_fields(): void
    {
        $industry = Industry::factory()->create([
            'name' => 'Food Service',
            'slug' => 'food-service',
            'depth' => 0,
            'sort' => 10,
            'is_active' => true,
            'visible_in_ui' => true,
        ]);

        $resource = new IndustryResource($industry);
        $response = $resource->toArray(new Request);

        // Test included fields
        $this->assertArrayHasKey('id', $response);
        $this->assertArrayHasKey('name', $response);
        $this->assertArrayHasKey('slug', $response);
        $this->assertArrayHasKey('depth', $response);
        $this->assertArrayHasKey('sort', $response);
        $this->assertArrayHasKey('breadcrumbs', $response);
        $this->assertArrayHasKey('children_count', $response);

        // Test field values
        $this->assertEquals($industry->id, $response['id']);
        $this->assertEquals('Food Service', $response['name']);
        $this->assertEquals('food-service', $response['slug']);
        $this->assertEquals(0, $response['depth']);
        $this->assertEquals(10, $response['sort']);

        // Test excluded fields
        $this->assertArrayNotHasKey('is_active', $response);
        $this->assertArrayNotHasKey('visible_in_ui', $response);
        $this->assertArrayNotHasKey('path', $response);
        $this->assertArrayNotHasKey('parent_id', $response);
        $this->assertArrayNotHasKey('created_at', $response);
        $this->assertArrayNotHasKey('updated_at', $response);
    }

    /**
     * Test parent object format when relationship is loaded.
     */
    public function test_parent_object_format_when_loaded(): void
    {
        $parent = Industry::factory()->create([
            'name' => 'Food Service',
            'slug' => 'food-service',
        ]);

        $child = Industry::factory()->child($parent)->create([
            'name' => 'Quick Service',
            'slug' => 'quick-service',
        ]);

        // Load the parent relationship
        $childWithParent = Industry::with('parent')->find($child->id);

        $resource = new IndustryResource($childWithParent);
        $response = $resource->toArray(new Request);

        // Test parent object structure
        $this->assertArrayHasKey('parent', $response);
        $this->assertIsArray($response['parent']);
        $this->assertArrayHasKey('id', $response['parent']);
        $this->assertArrayHasKey('name', $response['parent']);
        $this->assertArrayHasKey('slug', $response['parent']);

        // Test parent object values
        $this->assertEquals($parent->id, $response['parent']['id']);
        $this->assertEquals('Food Service', $response['parent']['name']);
        $this->assertEquals('food-service', $response['parent']['slug']);
    }

    /**
     * Test parent is not included when relationship is not loaded.
     */
    public function test_parent_is_not_included_when_not_loaded(): void
    {
        $parent = Industry::factory()->create();
        $child = Industry::factory()->child($parent)->create();

        // Don't load the parent relationship
        $resource = new IndustryResource($child);
        $response = $resource->toResponse(new Request);
        $data = json_decode($response->getContent(), true)['data'];

        $this->assertArrayNotHasKey('parent', $data);
    }

    /**
     * Test breadcrumbs array format is correct.
     */
    public function test_breadcrumbs_array_format(): void
    {
        $root = Industry::factory()->create([
            'name' => 'Food Service',
            'slug' => 'food-service',
        ]);

        $child = Industry::factory()->child($root)->create([
            'name' => 'Quick Service',
            'slug' => 'quick-service',
        ]);

        $grandchild = Industry::factory()->child($child)->create([
            'name' => 'Burgers',
            'slug' => 'burgers',
        ]);

        $resource = new IndustryResource($grandchild);
        $response = $resource->toArray(new Request);

        $this->assertArrayHasKey('breadcrumbs', $response);
        $this->assertIsArray($response['breadcrumbs']);
        $this->assertCount(3, $response['breadcrumbs']);

        // Test first breadcrumb
        $this->assertArrayHasKey('name', $response['breadcrumbs'][0]);
        $this->assertArrayHasKey('slug', $response['breadcrumbs'][0]);
        $this->assertEquals('Food Service', $response['breadcrumbs'][0]['name']);
        $this->assertEquals('food-service', $response['breadcrumbs'][0]['slug']);

        // Test second breadcrumb
        $this->assertEquals('Quick Service', $response['breadcrumbs'][1]['name']);
        $this->assertEquals('quick-service', $response['breadcrumbs'][1]['slug']);

        // Test third breadcrumb
        $this->assertEquals('Burgers', $response['breadcrumbs'][2]['name']);
        $this->assertEquals('burgers', $response['breadcrumbs'][2]['slug']);
    }

    /**
     * Test children count reflects only active and visible industries.
     */
    public function test_children_count_reflects_filtered_industries(): void
    {
        $parent = Industry::factory()->create();

        // Create active and visible child
        Industry::factory()->child($parent)->create(['is_active' => true, 'visible_in_ui' => true]);

        // Create inactive child (should not count)
        Industry::factory()->child($parent)->create(['is_active' => false, 'visible_in_ui' => true]);

        // Create hidden child (should not count)
        Industry::factory()->child($parent)->create(['is_active' => true, 'visible_in_ui' => false]);

        // Create inactive and hidden child (should not count)
        Industry::factory()->child($parent)->create(['is_active' => false, 'visible_in_ui' => false]);

        $resource = new IndustryResource($parent);
        $response = $resource->toArray(new Request);

        $this->assertEquals(1, $response['children_count']);
    }

    /**
     * Test IndustryNodeResource includes children array.
     */
    public function test_industry_node_resource_includes_children_array(): void
    {
        $parent = Industry::factory()->create(['name' => 'Food Service']);
        $child1 = Industry::factory()->child($parent)->create(['name' => 'Quick Service']);
        $child2 = Industry::factory()->child($parent)->create(['name' => 'Full Service']);

        // Load children relationship
        $parentWithChildren = Industry::with('children')->find($parent->id);

        $resource = new IndustryNodeResource($parentWithChildren);
        $response = $resource->toArray(new Request);

        // Test that all basic fields from IndustryResource are present
        $this->assertArrayHasKey('id', $response);
        $this->assertArrayHasKey('name', $response);
        $this->assertArrayHasKey('children_count', $response);

        // Test children array is present
        $this->assertArrayHasKey('children', $response);
        $this->assertIsArray($response['children']);
        $this->assertCount(2, $response['children']);

        // Test children are properly formatted
        $firstChild = $response['children'][0];
        $this->assertArrayHasKey('id', $firstChild);
        $this->assertArrayHasKey('name', $firstChild);
        $this->assertArrayHasKey('slug', $firstChild);
        $this->assertArrayHasKey('children', $firstChild); // Should also have children array
    }

    /**
     * Test children is empty array when relationship not loaded.
     */
    public function test_children_is_empty_when_not_loaded(): void
    {
        $parent = Industry::factory()->create();
        Industry::factory()->child($parent)->create();

        // Don't load children relationship
        $resource = new IndustryNodeResource($parent);
        $response = $resource->toArray(new Request);

        $this->assertArrayHasKey('children', $response);
        $this->assertIsArray($response['children']);
        $this->assertEmpty($response['children']);
    }

    /**
     * Test resource collections work properly.
     */
    public function test_resource_collections_work_properly(): void
    {
        $industries = Industry::factory()->count(3)->create();

        $collection = IndustryResource::collection($industries);
        $response = $collection->toArray(new Request);

        $this->assertIsArray($response);
        $this->assertCount(3, $response);

        foreach ($response as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('slug', $item);
            $this->assertArrayNotHasKey('is_active', $item);
        }
    }

    /**
     * Test resources handle null/missing relationships gracefully.
     */
    public function test_resources_handle_null_relationships_gracefully(): void
    {
        $industry = Industry::factory()->create();

        $resource = new IndustryResource($industry);
        $response = $resource->toResponse(new Request);
        $data = json_decode($response->getContent(), true)['data'];

        // Should not throw errors when relationships are not loaded
        $this->assertArrayNotHasKey('parent', $data);
        $this->assertIsArray($data['breadcrumbs']);
        $this->assertIsNumeric($data['children_count']);

        // Test with IndustryNodeResource
        $nodeResource = new IndustryNodeResource($industry);
        $nodeResponse = $nodeResource->toResponse(new Request);
        $nodeData = json_decode($nodeResponse->getContent(), true)['data'];

        $this->assertIsArray($nodeData['children']);
        $this->assertEmpty($nodeData['children']);
    }
}
