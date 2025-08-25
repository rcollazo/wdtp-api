<?php

namespace Tests\Unit;

use App\Models\Industry;
use App\Models\PositionCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PositionCategoryModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test industry
        $this->industry = Industry::factory()->create([
            'name' => 'Test Industry',
            'slug' => 'test-industry',
        ]);
    }

    public function test_position_category_has_correct_fillable_attributes(): void
    {
        $fillable = (new PositionCategory)->getFillable();

        $expected = [
            'name',
            'slug',
            'description',
            'industry_id',
            'status',
        ];

        $this->assertEquals($expected, $fillable);
    }

    public function test_position_category_casts_attributes_correctly(): void
    {
        $position = new PositionCategory;

        $casts = $position->getCasts();

        $this->assertArrayHasKey('industry_id', $casts);
        $this->assertEquals('integer', $casts['industry_id']);
    }

    public function test_position_category_belongs_to_industry(): void
    {
        $position = PositionCategory::factory()->create([
            'industry_id' => $this->industry->id,
        ]);

        $this->assertInstanceOf(Industry::class, $position->industry);
        $this->assertEquals($this->industry->id, $position->industry->id);
    }

    public function test_slug_is_auto_generated_from_name_on_create(): void
    {
        $position = PositionCategory::create([
            'name' => 'Test Position Name',
            'description' => 'Test description',
            'industry_id' => $this->industry->id,
            'status' => 'active',
        ]);

        $this->assertEquals('test-position-name', $position->slug);
    }

    public function test_slug_is_updated_when_name_changes_and_slug_is_empty(): void
    {
        $position = PositionCategory::create([
            'name' => 'Original Name',
            'description' => 'Test description',
            'industry_id' => $this->industry->id,
            'status' => 'active',
        ]);

        $this->assertEquals('original-name', $position->slug);

        // Update name with empty slug should regenerate slug
        $position->update([
            'name' => 'Updated Name',
            'slug' => '',
        ]);

        $position->refresh();
        $this->assertEquals('updated-name', $position->slug);
    }

    public function test_manual_slug_is_preserved_when_name_changes(): void
    {
        $position = PositionCategory::create([
            'name' => 'Original Name',
            'slug' => 'custom-slug',
            'description' => 'Test description',
            'industry_id' => $this->industry->id,
            'status' => 'active',
        ]);

        $this->assertEquals('custom-slug', $position->slug);

        // Update name but keep custom slug
        $position->update([
            'name' => 'Updated Name',
        ]);

        $position->refresh();
        $this->assertEquals('custom-slug', $position->slug);
    }

    public function test_active_scope_filters_active_positions_only(): void
    {
        // Create active and inactive positions
        PositionCategory::factory()->create([
            'name' => 'Active Position',
            'industry_id' => $this->industry->id,
            'status' => 'active',
        ]);

        PositionCategory::factory()->create([
            'name' => 'Inactive Position',
            'industry_id' => $this->industry->id,
            'status' => 'inactive',
        ]);

        $activePositions = PositionCategory::active()->get();

        $this->assertCount(1, $activePositions);
        $this->assertEquals('active', $activePositions->first()->status);
        $this->assertEquals('Active Position', $activePositions->first()->name);
    }

    public function test_for_industry_scope_filters_by_industry_id(): void
    {
        $otherIndustry = Industry::factory()->create(['name' => 'Other Industry']);

        // Create positions for different industries
        $position1 = PositionCategory::factory()->create([
            'name' => 'Position 1',
            'industry_id' => $this->industry->id,
        ]);

        PositionCategory::factory()->create([
            'name' => 'Position 2',
            'industry_id' => $otherIndustry->id,
        ]);

        $positions = PositionCategory::forIndustry($this->industry->id)->get();

        $this->assertCount(1, $positions);
        $this->assertEquals($position1->id, $positions->first()->id);
        $this->assertEquals($this->industry->id, $positions->first()->industry_id);
    }

    public function test_search_scope_searches_name_and_description(): void
    {
        // Create positions with different names and descriptions
        PositionCategory::factory()->create([
            'name' => 'Server Position',
            'description' => 'Takes customer orders',
            'industry_id' => $this->industry->id,
        ]);

        PositionCategory::factory()->create([
            'name' => 'Cook Position',
            'description' => 'Prepares food for service',
            'industry_id' => $this->industry->id,
        ]);

        PositionCategory::factory()->create([
            'name' => 'Manager Position',
            'description' => 'Manages restaurant operations',
            'industry_id' => $this->industry->id,
        ]);

        // Search by name
        $results = PositionCategory::search('server')->get();
        $this->assertCount(1, $results);
        $this->assertEquals('Server Position', $results->first()->name);

        // Search by description
        $results = PositionCategory::search('food')->get();
        $this->assertCount(1, $results);
        $this->assertEquals('Cook Position', $results->first()->name);

        // Search case insensitive
        $results = PositionCategory::search('SERVICE')->get();
        $this->assertCount(1, $results);
        $this->assertEquals('Cook Position', $results->first()->name);

        // Search partial match
        $results = PositionCategory::search('manag')->get();
        $this->assertCount(1, $results);
        $this->assertEquals('Manager Position', $results->first()->name);
    }

    public function test_resolve_route_binding_works_with_id(): void
    {
        $position = PositionCategory::factory()->create([
            'name' => 'Test Position',
            'industry_id' => $this->industry->id,
        ]);

        $resolved = (new PositionCategory)->resolveRouteBinding($position->id);

        $this->assertInstanceOf(PositionCategory::class, $resolved);
        $this->assertEquals($position->id, $resolved->id);
    }

    public function test_resolve_route_binding_works_with_slug(): void
    {
        $position = PositionCategory::factory()->create([
            'name' => 'Test Position',
            'slug' => 'test-position-slug',
            'industry_id' => $this->industry->id,
        ]);

        $resolved = (new PositionCategory)->resolveRouteBinding('test-position-slug');

        $this->assertInstanceOf(PositionCategory::class, $resolved);
        $this->assertEquals($position->id, $resolved->id);
        $this->assertEquals('test-position-slug', $resolved->slug);
    }

    public function test_resolve_route_binding_tries_id_first_for_numeric_values(): void
    {
        $position1 = PositionCategory::factory()->create([
            'name' => 'Position 1',
            'slug' => '123',
            'industry_id' => $this->industry->id,
        ]);

        $position2 = PositionCategory::factory()->create([
            'name' => 'Position 2',
            'industry_id' => $this->industry->id,
        ]);

        // Should resolve by ID first, not slug
        $resolved = (new PositionCategory)->resolveRouteBinding($position2->id);

        $this->assertEquals($position2->id, $resolved->id);
        $this->assertEquals('Position 2', $resolved->name);
    }

    public function test_resolve_route_binding_returns_null_for_non_existent(): void
    {
        $resolved = (new PositionCategory)->resolveRouteBinding('non-existent');

        $this->assertNull($resolved);
    }

    public function test_resolve_route_binding_with_explicit_field(): void
    {
        $position = PositionCategory::factory()->create([
            'name' => 'Test Position',
            'slug' => 'test-slug',
            'industry_id' => $this->industry->id,
        ]);

        $resolved = (new PositionCategory)->resolveRouteBinding('test-slug', 'slug');

        $this->assertInstanceOf(PositionCategory::class, $resolved);
        $this->assertEquals($position->id, $resolved->id);
    }

    public function test_scopes_can_be_chained(): void
    {
        $otherIndustry = Industry::factory()->create(['name' => 'Other Industry']);

        // Create various positions
        PositionCategory::factory()->create([
            'name' => 'Active Server',
            'description' => 'Serves customers',
            'industry_id' => $this->industry->id,
            'status' => 'active',
        ]);

        PositionCategory::factory()->create([
            'name' => 'Inactive Server',
            'description' => 'Serves customers',
            'industry_id' => $this->industry->id,
            'status' => 'inactive',
        ]);

        PositionCategory::factory()->create([
            'name' => 'Active Server',
            'description' => 'Serves customers',
            'industry_id' => $otherIndustry->id,
            'status' => 'active',
        ]);

        // Chain scopes: active + for specific industry + search
        $results = PositionCategory::active()
            ->forIndustry($this->industry->id)
            ->search('server')
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Active Server', $results->first()->name);
        $this->assertEquals('active', $results->first()->status);
        $this->assertEquals($this->industry->id, $results->first()->industry_id);
    }

    public function test_hidden_attributes_are_correct(): void
    {
        $position = new PositionCategory;
        $hidden = $position->getHidden();

        // PositionCategory doesn't have any hidden fields like password
        // but we test the method works correctly
        $this->assertIsArray($hidden);
    }

    public function test_timestamps_are_working(): void
    {
        $position = PositionCategory::factory()->create([
            'industry_id' => $this->industry->id,
        ]);

        $this->assertNotNull($position->created_at);
        $this->assertNotNull($position->updated_at);
        $this->assertInstanceOf(\Carbon\Carbon::class, $position->created_at);
        $this->assertInstanceOf(\Carbon\Carbon::class, $position->updated_at);
    }

    public function test_table_name_is_correct(): void
    {
        $position = new PositionCategory;
        $this->assertEquals('position_categories', $position->getTable());
    }

    public function test_primary_key_is_correct(): void
    {
        $position = new PositionCategory;
        $this->assertEquals('id', $position->getKeyName());
        $this->assertTrue($position->getIncrementing());
        $this->assertEquals('int', $position->getKeyType());
    }

    public function test_position_category_attributes_can_be_mass_assigned(): void
    {
        $data = [
            'name' => 'Test Position',
            'slug' => 'test-position',
            'description' => 'Test description',
            'industry_id' => $this->industry->id,
            'status' => 'active',
        ];

        $position = new PositionCategory($data);

        $this->assertEquals('Test Position', $position->name);
        $this->assertEquals('test-position', $position->slug);
        $this->assertEquals('Test description', $position->description);
        $this->assertEquals($this->industry->id, $position->industry_id);
        $this->assertEquals('active', $position->status);
    }

    public function test_position_category_can_be_converted_to_array(): void
    {
        $position = PositionCategory::factory()->create([
            'industry_id' => $this->industry->id,
        ]);

        $array = $position->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('slug', $array);
        $this->assertArrayHasKey('description', $array);
        $this->assertArrayHasKey('industry_id', $array);
        $this->assertArrayHasKey('status', $array);
        $this->assertArrayHasKey('created_at', $array);
        $this->assertArrayHasKey('updated_at', $array);
    }

    public function test_position_category_can_be_converted_to_json(): void
    {
        $position = PositionCategory::factory()->create([
            'industry_id' => $this->industry->id,
        ]);

        $json = $position->toJson();
        $decoded = json_decode($json, true);

        $this->assertIsString($json);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('id', $decoded);
        $this->assertArrayHasKey('name', $decoded);
        $this->assertEquals($position->id, $decoded['id']);
        $this->assertEquals($position->name, $decoded['name']);
    }

    public function test_position_category_status_validation(): void
    {
        $validStatuses = ['active', 'inactive'];

        foreach ($validStatuses as $status) {
            $position = PositionCategory::factory()->create([
                'status' => $status,
                'industry_id' => $this->industry->id,
            ]);
            $this->assertEquals($status, $position->status);
        }
    }

    public function test_position_category_default_values(): void
    {
        // Test that defaults are applied correctly at database level
        $position = new PositionCategory([
            'name' => 'Test Position',
            'description' => 'Test description',
            'industry_id' => $this->industry->id,
        ]);

        // Save to database to get default values
        $position->save();
        $position->refresh();

        $this->assertEquals('active', $position->status);
        $this->assertEquals('test-position', $position->slug);
    }

    public function test_industry_relationship_deletion_cascade(): void
    {
        $position = PositionCategory::factory()->create([
            'industry_id' => $this->industry->id,
        ]);

        $positionId = $position->id;
        $this->assertDatabaseHas('position_categories', ['id' => $positionId]);

        // Delete the industry - should cascade delete the position
        $this->industry->delete();

        $this->assertDatabaseMissing('position_categories', ['id' => $positionId]);
    }
}
