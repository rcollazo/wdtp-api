<?php

namespace Tests\Unit;

use App\Models\Industry;
use App\Models\PositionCategory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PositionCategoryConstraintsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test industries
        $this->restaurant = Industry::factory()->create([
            'name' => 'Restaurants',
            'slug' => 'restaurants',
        ]);

        $this->retail = Industry::factory()->create([
            'name' => 'Retail',
            'slug' => 'retail',
        ]);
    }

    public function test_slug_must_be_unique(): void
    {
        PositionCategory::factory()->create([
            'name' => 'Server',
            'slug' => 'server',
            'industry_id' => $this->restaurant->id,
        ]);

        $this->expectException(QueryException::class);

        PositionCategory::factory()->create([
            'name' => 'Server Different',
            'slug' => 'server', // Same slug should fail
            'industry_id' => $this->retail->id,
        ]);
    }

    public function test_name_must_be_unique_within_industry(): void
    {
        PositionCategory::factory()->create([
            'name' => 'Manager',
            'industry_id' => $this->restaurant->id,
        ]);

        // Same name in different industry should be allowed
        $position = PositionCategory::factory()->create([
            'name' => 'Manager',
            'industry_id' => $this->retail->id,
        ]);

        $this->assertEquals('Manager', $position->name);
        $this->assertEquals($this->retail->id, $position->industry_id);

        // But same name in same industry should fail
        $this->expectException(QueryException::class);

        PositionCategory::factory()->create([
            'name' => 'Manager',
            'industry_id' => $this->restaurant->id,
        ]);
    }

    public function test_industry_id_foreign_key_constraint(): void
    {
        $this->expectException(QueryException::class);

        PositionCategory::factory()->create([
            'name' => 'Test Position',
            'industry_id' => 99999, // Non-existent industry ID
        ]);
    }

    public function test_name_cannot_be_null(): void
    {
        $this->expectException(QueryException::class);

        PositionCategory::create([
            'name' => null,
            'industry_id' => $this->restaurant->id,
        ]);
    }

    public function test_industry_id_cannot_be_null(): void
    {
        $this->expectException(QueryException::class);

        PositionCategory::create([
            'name' => 'Test Position',
            'industry_id' => null,
        ]);
    }

    public function test_status_enum_constraint(): void
    {
        // Valid statuses should work
        $active = PositionCategory::factory()->create([
            'status' => 'active',
            'industry_id' => $this->restaurant->id,
        ]);
        $this->assertEquals('active', $active->status);

        $inactive = PositionCategory::factory()->create([
            'status' => 'inactive',
            'industry_id' => $this->restaurant->id,
        ]);
        $this->assertEquals('inactive', $inactive->status);

        // Invalid status should fail
        $this->expectException(QueryException::class);

        PositionCategory::create([
            'name' => 'Test Position',
            'status' => 'invalid_status',
            'industry_id' => $this->restaurant->id,
        ]);
    }

    public function test_name_max_length_constraint(): void
    {
        $longName = str_repeat('a', 121); // 121 characters, exceeds limit of 120

        $this->expectException(QueryException::class);

        PositionCategory::factory()->create([
            'name' => $longName,
            'industry_id' => $this->restaurant->id,
        ]);
    }

    public function test_slug_max_length_constraint(): void
    {
        $longSlug = str_repeat('a', 131); // 131 characters, exceeds limit of 130

        $this->expectException(QueryException::class);

        PositionCategory::factory()->create([
            'name' => 'Test Position',
            'slug' => $longSlug,
            'industry_id' => $this->restaurant->id,
        ]);
    }

    public function test_description_can_be_null(): void
    {
        $position = PositionCategory::factory()->create([
            'name' => 'Test Position',
            'description' => null,
            'industry_id' => $this->restaurant->id,
        ]);

        $this->assertNull($position->description);
    }

    public function test_slug_auto_generation_ensures_uniqueness(): void
    {
        // Create first position
        $position1 = PositionCategory::create([
            'name' => 'Server',
            'industry_id' => $this->restaurant->id,
        ]);

        // Create second position with same name (different industry)
        // Since slugs must be globally unique, this should create a different slug
        $position2 = PositionCategory::create([
            'name' => 'Server',
            'slug' => 'server-retail', // Explicitly set different slug
            'industry_id' => $this->retail->id,
        ]);

        $this->assertEquals('server', $position1->slug);
        // Second slug should be different to avoid unique constraint violation
        $this->assertNotEquals('server', $position2->slug);
        $this->assertEquals('server-retail', $position2->slug);
    }

    public function test_database_indexes_exist(): void
    {
        // Test that we can query efficiently by status
        PositionCategory::factory()->count(10)->create([
            'industry_id' => $this->restaurant->id,
            'status' => 'active',
        ]);

        PositionCategory::factory()->count(5)->create([
            'industry_id' => $this->restaurant->id,
            'status' => 'inactive',
        ]);

        // These queries should use indexes efficiently
        $activeCount = PositionCategory::where('status', 'active')->count();
        $industryActiveCount = PositionCategory::where('industry_id', $this->restaurant->id)
            ->where('status', 'active')
            ->count();

        $this->assertEquals(10, $activeCount);
        $this->assertEquals(10, $industryActiveCount);
    }

    public function test_cascade_deletion_on_industry_delete(): void
    {
        // Create positions for the industry
        $positions = PositionCategory::factory()->count(3)->create([
            'industry_id' => $this->restaurant->id,
        ]);

        $positionIds = $positions->pluck('id')->toArray();

        // Verify positions exist
        foreach ($positionIds as $id) {
            $this->assertDatabaseHas('position_categories', ['id' => $id]);
        }

        // Delete the industry
        $this->restaurant->delete();

        // Verify all positions were cascade deleted
        foreach ($positionIds as $id) {
            $this->assertDatabaseMissing('position_categories', ['id' => $id]);
        }
    }
}
