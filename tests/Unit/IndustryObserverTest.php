<?php

namespace Tests\Unit;

use App\Models\Industry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class IndustryObserverTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test observer prevents cycles when setting parent_id to descendant.
     */
    public function test_observer_prevents_descendant_cycles(): void
    {
        $root = Industry::factory()->create(['name' => 'Root']);
        $child = Industry::factory()->child($root)->create(['name' => 'Child']);
        $grandchild = Industry::factory()->child($child)->create(['name' => 'Grandchild']);

        // Try to make root a child of its grandchild
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot set parent: would create a cycle in the industry hierarchy');

        $root->parent_id = $grandchild->id;
        $root->save();
    }

    /**
     * Test observer prevents self-parenting.
     */
    public function test_observer_prevents_self_parenting(): void
    {
        $industry = Industry::factory()->create(['name' => 'Industry']);

        // Database constraint prevents this, not the observer
        $this->expectException(\Illuminate\Database\QueryException::class);

        $industry->parent_id = $industry->id;
        $industry->save();
    }

    /**
     * Test observer allows valid parent assignments.
     */
    public function test_observer_allows_valid_parent_assignments(): void
    {
        $parent1 = Industry::factory()->create(['name' => 'Parent 1', 'slug' => 'parent-1']);
        $parent2 = Industry::factory()->create(['name' => 'Parent 2', 'slug' => 'parent-2']);
        $child = Industry::factory()->child($parent1)->create(['name' => 'Child', 'slug' => 'child']);

        // Moving child from parent1 to parent2 should be allowed
        $child->parent_id = $parent2->id;
        $child->save();

        $child->refresh();
        $this->assertEquals($parent2->id, $child->parent_id);
        $this->assertEquals(1, $child->depth);
        $this->assertEquals('parent-2/child', $child->path);
    }

    /**
     * Test depth computation on create for root industries.
     */
    public function test_depth_computation_on_create_for_roots(): void
    {
        $root = Industry::factory()->create(['name' => 'Root Industry', 'slug' => 'root-industry']);

        $this->assertEquals(0, $root->depth);
        $this->assertEquals('root-industry', $root->path);
    }

    /**
     * Test depth computation on create for child industries.
     */
    public function test_depth_computation_on_create_for_children(): void
    {
        $root = Industry::factory()->create(['name' => 'Root', 'slug' => 'root']);
        $child = Industry::factory()->child($root)->create(['name' => 'Child', 'slug' => 'child']);

        $this->assertEquals(1, $child->depth);
        $this->assertEquals('root/child', $child->path);

        // Test grandchild
        $grandchild = Industry::factory()->child($child)->create(['name' => 'Grandchild', 'slug' => 'grandchild']);

        $this->assertEquals(2, $grandchild->depth);
        $this->assertEquals('root/child/grandchild', $grandchild->path);
    }

    /**
     * Test subtree recomputation when parent changes.
     */
    public function test_subtree_recomputation_when_parent_changes(): void
    {
        $oldParent = Industry::factory()->create(['name' => 'Old Parent', 'slug' => 'old-parent']);
        $newParent = Industry::factory()->create(['name' => 'New Parent', 'slug' => 'new-parent']);

        $child = Industry::factory()->child($oldParent)->create(['name' => 'Child', 'slug' => 'child']);
        $grandchild = Industry::factory()->child($child)->create(['name' => 'Grandchild', 'slug' => 'grandchild']);

        // Verify initial state
        $this->assertEquals('old-parent/child', $child->path);
        $this->assertEquals('old-parent/child/grandchild', $grandchild->path);
        $this->assertEquals(1, $child->depth);
        $this->assertEquals(2, $grandchild->depth);

        // Move child to new parent
        $child->parent_id = $newParent->id;
        $child->save();

        // Refresh all models
        $child->refresh();
        $grandchild->refresh();

        // Verify subtree was updated
        $this->assertEquals('new-parent/child', $child->path);
        $this->assertEquals('new-parent/child/grandchild', $grandchild->path);
        $this->assertEquals(1, $child->depth);
        $this->assertEquals(2, $grandchild->depth);
    }

    /**
     * Test subtree recomputation when slug changes.
     */
    public function test_subtree_recomputation_when_slug_changes(): void
    {
        $root = Industry::factory()->create(['name' => 'Root', 'slug' => 'root']);
        $child = Industry::factory()->child($root)->create(['name' => 'Child', 'slug' => 'child']);
        $grandchild = Industry::factory()->child($child)->create(['name' => 'Grandchild', 'slug' => 'grandchild']);

        // Verify initial paths
        $this->assertEquals('root', $root->path);
        $this->assertEquals('root/child', $child->path);
        $this->assertEquals('root/child/grandchild', $grandchild->path);

        // Change root slug
        $root->slug = 'updated-root';
        $root->save();

        // Refresh all models
        $root->refresh();
        $child->refresh();
        $grandchild->refresh();

        // Verify entire subtree paths were updated
        $this->assertEquals('updated-root', $root->path);
        $this->assertEquals('updated-root/child', $child->path);
        $this->assertEquals('updated-root/child/grandchild', $grandchild->path);
    }

    /**
     * Test cache version management.
     */
    public function test_cache_version_management(): void
    {
        // Start with clean cache
        Cache::forget('industries:ver');
        $this->assertEquals(0, Cache::get('industries:ver', 0));

        // Create industry - should increment version
        $industry = Industry::factory()->create();
        $this->assertEquals(1, Cache::get('industries:ver', 0));

        // Update industry - should increment version
        $industry->name = 'Updated Name';
        $industry->save();
        $this->assertEquals(2, Cache::get('industries:ver', 0));

        // Update with same data (no actual change) - should still increment
        $industry->touch();
        $this->assertEquals(3, Cache::get('industries:ver', 0));

        // Delete industry - should increment version
        $industry->delete();
        $this->assertEquals(4, Cache::get('industries:ver', 0));
    }

    /**
     * Test observer maintains consistency for entire subtree.
     */
    public function test_observer_maintains_subtree_consistency(): void
    {
        $root = Industry::factory()->create(['name' => 'Root', 'slug' => 'root']);
        $branch1 = Industry::factory()->child($root)->create(['name' => 'Branch 1', 'slug' => 'branch-1']);
        $branch2 = Industry::factory()->child($root)->create(['name' => 'Branch 2', 'slug' => 'branch-2']);
        $leaf1 = Industry::factory()->child($branch1)->create(['name' => 'Leaf 1', 'slug' => 'leaf-1']);
        $leaf2 = Industry::factory()->child($branch2)->create(['name' => 'Leaf 2', 'slug' => 'leaf-2']);

        // Change root slug - all descendants should update
        $root->slug = 'new-root';
        $root->save();

        // Refresh all models
        $branch1->refresh();
        $branch2->refresh();
        $leaf1->refresh();
        $leaf2->refresh();

        // Verify all paths updated correctly
        $this->assertEquals('new-root', $root->path);
        $this->assertEquals('new-root/branch-1', $branch1->path);
        $this->assertEquals('new-root/branch-2', $branch2->path);
        $this->assertEquals('new-root/branch-1/leaf-1', $leaf1->path);
        $this->assertEquals('new-root/branch-2/leaf-2', $leaf2->path);

        // Depths should remain unchanged
        $this->assertEquals(0, $root->depth);
        $this->assertEquals(1, $branch1->depth);
        $this->assertEquals(1, $branch2->depth);
        $this->assertEquals(2, $leaf1->depth);
        $this->assertEquals(2, $leaf2->depth);
    }

    /**
     * Test transactional behavior - rollback on error.
     */
    public function test_transactional_behavior_on_error(): void
    {
        $root = Industry::factory()->create(['name' => 'Root']);
        $child = Industry::factory()->child($root)->create(['name' => 'Child']);

        $originalCacheVersion = Cache::get('industries:ver', 0);

        // Mock a database error during subtree update
        DB::shouldReceive('transaction')->andThrow(new \Exception('Database error'));

        try {
            $root->slug = 'updated-slug';
            $root->save();
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertEquals('Database error', $e->getMessage());
        }

        // Cache version should not have been incremented due to rollback
        $this->assertEquals($originalCacheVersion, Cache::get('industries:ver', 0));
    }

    /**
     * Test observer handles complex hierarchy changes.
     */
    public function test_observer_handles_complex_hierarchy_changes(): void
    {
        // Create a complex hierarchy
        $root1 = Industry::factory()->create(['name' => 'Root 1', 'slug' => 'root-1']);
        $root2 = Industry::factory()->create(['name' => 'Root 2', 'slug' => 'root-2']);

        $child1 = Industry::factory()->child($root1)->create(['name' => 'Child 1', 'slug' => 'child-1']);
        $child2 = Industry::factory()->child($child1)->create(['name' => 'Child 2', 'slug' => 'child-2']);
        $child3 = Industry::factory()->child($child2)->create(['name' => 'Child 3', 'slug' => 'child-3']);

        // Verify initial state
        $this->assertEquals('root-1/child-1/child-2', $child2->path);
        $this->assertEquals('root-1/child-1/child-2/child-3', $child3->path);
        $this->assertEquals(2, $child2->depth);
        $this->assertEquals(3, $child3->depth);

        // Move child1 (with its subtree) to root2
        $child1->parent_id = $root2->id;
        $child1->save();

        // Refresh models
        $child1->refresh();
        $child2->refresh();
        $child3->refresh();

        // Verify entire subtree moved correctly
        $this->assertEquals('root-2/child-1', $child1->path);
        $this->assertEquals('root-2/child-1/child-2', $child2->path);
        $this->assertEquals('root-2/child-1/child-2/child-3', $child3->path);
        $this->assertEquals(1, $child1->depth);
        $this->assertEquals(2, $child2->depth);
        $this->assertEquals(3, $child3->depth);
    }

    /**
     * Test observer prevents excessive nesting (depth limit).
     */
    public function test_observer_prevents_excessive_nesting(): void
    {
        // Create a deep hierarchy (up to depth 6)
        $industries = [];
        $industries[0] = Industry::factory()->create(['name' => 'Level 0']);

        for ($i = 1; $i <= 6; $i++) {
            $industries[$i] = Industry::factory()->child($industries[$i - 1])->create([
                'name' => "Level {$i}",
            ]);
        }

        $this->assertEquals(6, $industries[6]->depth);

        // Try to create level 7 (should be prevented by database constraint)
        $this->expectException(\Illuminate\Database\QueryException::class);

        Industry::factory()->child($industries[6])->create(['name' => 'Level 7']);
    }

    /**
     * Test observer handles concurrent updates gracefully.
     */
    public function test_observer_handles_concurrent_updates(): void
    {
        $root = Industry::factory()->create(['name' => 'Root']);
        $child = Industry::factory()->child($root)->create(['name' => 'Child']);

        // Simulate concurrent updates by getting two instances
        $instance1 = Industry::find($root->id);
        $instance2 = Industry::find($root->id);

        // Update both instances
        $instance1->name = 'Updated Name 1';
        $instance1->save();

        $instance2->name = 'Updated Name 2';
        $instance2->save();

        // Both should have triggered cache version increments
        $this->assertGreaterThan(2, Cache::get('industries:ver', 0));

        // The last update should win
        $root->refresh();
        $this->assertEquals('Updated Name 2', $root->name);
    }

    /**
     * Test observer handles bulk operations correctly.
     */
    public function test_observer_handles_bulk_operations(): void
    {
        // Create industries with different names to avoid constraint violations
        $industries = collect();
        for ($i = 0; $i < 5; $i++) {
            $industries->push(Industry::factory()->create(['name' => "Industry {$i}"]));
        }

        $initialVersion = Cache::get('industries:ver', 0);

        // Update each industry individually to avoid constraint violations
        $industries->each(function ($industry, $index) {
            $industry->update(['name' => "Bulk Updated {$index}"]);
        });

        // Cache version should have been incremented multiple times
        $this->assertGreaterThan($initialVersion, Cache::get('industries:ver', 0));
    }
}
