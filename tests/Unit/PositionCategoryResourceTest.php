<?php

namespace Tests\Unit;

use App\Http\Resources\PositionCategoryResource;
use App\Models\Industry;
use App\Models\PositionCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PositionCategoryResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->industry = Industry::factory()->create([
            'name' => 'Test Industry',
            'slug' => 'test-industry',
        ]);
    }

    public function test_position_category_resource_structure(): void
    {
        $position = PositionCategory::factory()->create([
            'industry_id' => $this->industry->id,
            'name' => 'Test Position',
            'slug' => 'test-position',
            'description' => 'Test description',
            'status' => 'active',
        ]);

        $resource = new PositionCategoryResource($position);
        $request = Request::create('/test');
        $response = $resource->toArray($request);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('id', $response);
        $this->assertArrayHasKey('name', $response);
        $this->assertArrayHasKey('slug', $response);
        $this->assertArrayHasKey('description', $response);
        $this->assertArrayHasKey('status', $response);
        $this->assertArrayHasKey('industry', $response);
        $this->assertArrayHasKey('created_at', $response);
        $this->assertArrayHasKey('updated_at', $response);
    }

    public function test_position_category_resource_values(): void
    {
        $position = PositionCategory::factory()->create([
            'industry_id' => $this->industry->id,
            'name' => 'Server Position',
            'slug' => 'server-position',
            'description' => 'Serves customers food and drinks',
            'status' => 'active',
        ]);

        $resource = new PositionCategoryResource($position);
        $request = Request::create('/test');
        $response = $resource->toArray($request);

        $this->assertEquals($position->id, $response['id']);
        $this->assertEquals('Server Position', $response['name']);
        $this->assertEquals('server-position', $response['slug']);
        $this->assertEquals('Serves customers food and drinks', $response['description']);
        $this->assertEquals('active', $response['status']);
        $this->assertNotNull($response['created_at']);
        $this->assertNotNull($response['updated_at']);
    }

    public function test_position_category_resource_with_loaded_industry(): void
    {
        $position = PositionCategory::factory()->create([
            'industry_id' => $this->industry->id,
        ]);

        // Load the industry relationship
        $position->load('industry');

        $resource = new PositionCategoryResource($position);
        $request = Request::create('/test');
        $response = $resource->toArray($request);

        $this->assertNotNull($response['industry']);
        $this->assertIsArray($response['industry']);
        $this->assertArrayHasKey('id', $response['industry']);
        $this->assertArrayHasKey('name', $response['industry']);
        $this->assertArrayHasKey('slug', $response['industry']);

        $this->assertEquals($this->industry->id, $response['industry']['id']);
        $this->assertEquals($this->industry->name, $response['industry']['name']);
        $this->assertEquals($this->industry->slug, $response['industry']['slug']);
    }

    public function test_position_category_resource_without_loaded_industry(): void
    {
        $position = PositionCategory::factory()->create([
            'industry_id' => $this->industry->id,
        ]);

        // Don't load the industry relationship
        $resource = new PositionCategoryResource($position);
        $request = Request::create('/test');
        $response = $resource->toArray($request);

        // Industry should not be in response when not loaded (Laravel removes MissingValue)
        $this->assertArrayNotHasKey('industry', $response);
    }

    public function test_position_category_resource_handles_null_description(): void
    {
        $position = PositionCategory::factory()->create([
            'industry_id' => $this->industry->id,
            'description' => null,
        ]);

        $resource = new PositionCategoryResource($position);
        $request = Request::create('/test');
        $response = $resource->toArray($request);

        $this->assertArrayHasKey('description', $response);
        $this->assertNull($response['description']);
    }

    public function test_position_category_resource_collection(): void
    {
        $positions = PositionCategory::factory()->count(3)->create([
            'industry_id' => $this->industry->id,
        ]);

        $collection = PositionCategoryResource::collection($positions);
        $request = Request::create('/test');
        $response = $collection->toArray($request);

        $this->assertIsArray($response);
        $this->assertCount(3, $response);

        foreach ($response as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('slug', $item);
            $this->assertArrayHasKey('description', $item);
            $this->assertArrayHasKey('status', $item);
            $this->assertArrayHasKey('industry', $item);
            $this->assertArrayHasKey('created_at', $item);
            $this->assertArrayHasKey('updated_at', $item);
        }
    }

    public function test_position_category_resource_data_types(): void
    {
        $position = PositionCategory::factory()->create([
            'industry_id' => $this->industry->id,
        ]);

        $position->load('industry');

        $resource = new PositionCategoryResource($position);
        $request = Request::create('/test');
        $response = $resource->toArray($request);

        // Test data types
        $this->assertIsInt($response['id']);
        $this->assertIsString($response['name']);
        $this->assertIsString($response['slug']);
        $this->assertTrue(is_string($response['description']) || is_null($response['description']));
        $this->assertIsString($response['status']);
        $this->assertIsArray($response['industry']);

        // Timestamps are Carbon objects converted to string in JSON
        $this->assertTrue(is_string($response['created_at']) || $response['created_at'] instanceof \Carbon\Carbon);
        $this->assertTrue(is_string($response['updated_at']) || $response['updated_at'] instanceof \Carbon\Carbon);
    }

    public function test_position_category_resource_industry_nested_structure(): void
    {
        $position = PositionCategory::factory()->create([
            'industry_id' => $this->industry->id,
        ]);

        $position->load('industry');

        $resource = new PositionCategoryResource($position);
        $request = Request::create('/test');
        $response = $resource->toArray($request);

        $industry = $response['industry'];

        // Industry should only include id, name, slug (not all attributes)
        $this->assertCount(3, $industry);
        $this->assertArrayHasKey('id', $industry);
        $this->assertArrayHasKey('name', $industry);
        $this->assertArrayHasKey('slug', $industry);

        // Should not include timestamps or other internal fields
        $this->assertArrayNotHasKey('created_at', $industry);
        $this->assertArrayNotHasKey('updated_at', $industry);
        $this->assertArrayNotHasKey('parent_id', $industry);
        $this->assertArrayNotHasKey('depth', $industry);
    }

    public function test_position_category_resource_maintains_order_for_collection(): void
    {
        $positions = collect([
            PositionCategory::factory()->create([
                'name' => 'Alpha Position',
                'industry_id' => $this->industry->id,
            ]),
            PositionCategory::factory()->create([
                'name' => 'Beta Position',
                'industry_id' => $this->industry->id,
            ]),
            PositionCategory::factory()->create([
                'name' => 'Gamma Position',
                'industry_id' => $this->industry->id,
            ]),
        ]);

        $collection = PositionCategoryResource::collection($positions);
        $request = Request::create('/test');
        $response = $collection->toArray($request);

        $this->assertEquals('Alpha Position', $response[0]['name']);
        $this->assertEquals('Beta Position', $response[1]['name']);
        $this->assertEquals('Gamma Position', $response[2]['name']);
    }
}
