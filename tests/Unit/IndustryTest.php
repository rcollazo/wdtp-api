<?php

namespace Tests\Unit;

use App\Models\Industry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IndustryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test industry relationships work correctly.
     */
    public function test_industry_relationships(): void
    {
        $parent = Industry::factory()->create(['name' => 'Food Service']);
        $child1 = Industry::factory()->child($parent)->create(['name' => 'Quick Service']);
        $child2 = Industry::factory()->child($parent)->create(['name' => 'Full Service']);

        // Test parent relationship
        $this->assertEquals($parent->id, $child1->parent->id);
        $this->assertEquals($parent->name, $child1->parent->name);

        // Test children relationship
        $children = $parent->children;
        $this->assertCount(2, $children);
        $this->assertTrue($children->contains('id', $child1->id));
        $this->assertTrue($children->contains('id', $child2->id));

        // Test root has no parent
        $this->assertNull($parent->parent);
    }

    /**
     * Test industry scopes return expected results.
     */
    public function test_industry_scopes(): void
    {
        $rootActive = Industry::factory()->root()->create(['is_active' => true, 'visible_in_ui' => true]);
        $rootInactive = Industry::factory()->root()->inactive()->create();
        $rootHidden = Industry::factory()->root()->hidden()->create();
        $child = Industry::factory()->child($rootActive)->create();

        // Test roots scope
        $roots = Industry::roots()->get();
        $this->assertCount(3, $roots);
        $this->assertTrue($roots->contains('id', $rootActive->id));

        // Test active scope
        $active = Industry::active()->get();
        $this->assertCount(3, $active); // rootActive + child + rootHidden (hidden but active)
        $this->assertFalse($active->contains('id', $rootInactive->id));

        // Test visible scope
        $visible = Industry::visible()->get();
        $this->assertCount(3, $visible); // rootActive + child + rootInactive (inactive but visible)
        $this->assertFalse($visible->contains('id', $rootHidden->id));

        // Test default filters
        $defaultFiltered = Industry::defaultFilters()->get();
        $this->assertCount(2, $defaultFiltered); // rootActive + child

        // Test search scope
        $found = Industry::search('Food')->get();
        $this->assertCount(0, $found); // No matches

        // Test bySlug scope
        $foundBySlug = Industry::bySlug($rootActive->slug)->first();
        $this->assertEquals($rootActive->id, $foundBySlug->id);
    }

    /**
     * Test breadcrumbs method returns correct order.
     */
    public function test_breadcrumbs_method_returns_correct_order(): void
    {
        $root = Industry::factory()->create(['name' => 'Food Service', 'slug' => 'food-service']);
        $child = Industry::factory()->child($root)->create(['name' => 'Quick Service', 'slug' => 'quick-service']);
        $grandchild = Industry::factory()->child($child)->create(['name' => 'Burgers', 'slug' => 'burgers']);

        $breadcrumbs = $grandchild->breadcrumbs();

        $this->assertCount(3, $breadcrumbs);
        $this->assertEquals('Food Service', $breadcrumbs[0]->name);
        $this->assertEquals('Quick Service', $breadcrumbs[1]->name);
        $this->assertEquals('Burgers', $breadcrumbs[2]->name);
    }

    /**
     * Test cycle prevention blocks invalid parent assignments.
     */
    public function test_cycle_prevention_blocks_invalid_parent_assignments(): void
    {
        $root = Industry::factory()->create(['name' => 'Root']);
        $child = Industry::factory()->child($root)->create(['name' => 'Child']);
        $grandchild = Industry::factory()->child($child)->create(['name' => 'Grandchild']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot set parent: would create a cycle in the industry hierarchy');

        // Try to make root a child of grandchild - should throw exception
        $root->parent_id = $grandchild->id;
        $root->save();
    }

    /**
     * Test cache version increment on save/delete.
     */
    public function test_cache_version_increment_on_save_delete(): void
    {
        // Ensure cache starts at 0
        Cache::forget('industries:ver');
        $this->assertEquals(0, Cache::get('industries:ver', 0));

        // Create industry - should increment cache
        $industry = Industry::factory()->create();
        $this->assertEquals(1, Cache::get('industries:ver', 0));

        // Update industry - should increment cache
        $industry->name = 'Updated Name';
        $industry->save();
        $this->assertEquals(2, Cache::get('industries:ver', 0));

        // Delete industry - should increment cache
        $industry->delete();
        $this->assertEquals(3, Cache::get('industries:ver', 0));
    }

    /**
     * Test isRoot helper method.
     */
    public function test_is_root_helper(): void
    {
        $root = Industry::factory()->root()->create();
        $child = Industry::factory()->child($root)->create();

        $this->assertTrue($root->isRoot());
        $this->assertFalse($child->isRoot());
    }

    /**
     * Test getFullPath helper method.
     */
    public function test_get_full_path_helper(): void
    {
        $root = Industry::factory()->create(['name' => 'Food Service']);
        $child = Industry::factory()->child($root)->create(['name' => 'Quick Service']);
        $grandchild = Industry::factory()->child($child)->create(['name' => 'Burgers']);

        $this->assertEquals('Food Service', $root->getFullPath());
        $this->assertEquals('Food Service > Quick Service', $child->getFullPath());
        $this->assertEquals('Food Service > Quick Service > Burgers', $grandchild->getFullPath());
    }

    /**
     * Test buildTree static method.
     */
    public function test_build_tree_static_method(): void
    {
        $root1 = Industry::factory()->create(['name' => 'Food Service', 'sort' => 1]);
        $root2 = Industry::factory()->create(['name' => 'Retail', 'sort' => 2]);
        $child1 = Industry::factory()->child($root1)->create(['name' => 'Quick Service', 'sort' => 1]);
        $child2 = Industry::factory()->child($root1)->create(['name' => 'Full Service', 'sort' => 2]);

        $allIndustries = Industry::all();
        $tree = Industry::buildTree($allIndustries);

        // Should have 2 root industries
        $this->assertCount(2, $tree);

        // Find Food Service root
        $foodService = $tree->firstWhere('name', 'Food Service');
        $this->assertNotNull($foodService);
        $this->assertTrue(isset($foodService->nested_children));
        $this->assertCount(2, $foodService->nested_children);

        // Check sort order
        $firstChild = $foodService->nested_children->first();
        $this->assertEquals('Quick Service', $firstChild->name);
    }

    /**
     * Test route model binding works for both ID and slug.
     */
    public function test_route_model_binding(): void
    {
        $industry = Industry::factory()->create(['slug' => 'test-slug']);

        // Test ID binding
        $foundById = $industry->resolveRouteBinding($industry->id);
        $this->assertEquals($industry->id, $foundById->id);

        // Test slug binding
        $foundBySlug = $industry->resolveRouteBinding('test-slug');
        $this->assertEquals($industry->id, $foundBySlug->id);

        // Test explicit field binding
        $foundExplicit = $industry->resolveRouteBinding('test-slug', 'slug');
        $this->assertEquals($industry->id, $foundExplicit->id);

        // Test non-existent
        $notFound = $industry->resolveRouteBinding('non-existent');
        $this->assertNull($notFound);
    }

    /**
     * Test observer maintains path and depth consistency.
     */
    public function test_observer_maintains_path_and_depth(): void
    {
        $root = Industry::factory()->create(['name' => 'Root', 'slug' => 'root']);
        $this->assertEquals(0, $root->depth);
        $this->assertEquals('root', $root->path);

        $child = Industry::factory()->child($root)->create(['name' => 'Child', 'slug' => 'child']);
        $this->assertEquals(1, $child->depth);
        $this->assertEquals('root/child', $child->path);

        // Test updating slug updates path
        $child->slug = 'updated-child';
        $child->save();
        $child->refresh();
        $this->assertEquals('root/updated-child', $child->path);

        // Create grandchild to test subtree updates
        $grandchild = Industry::factory()->child($child)->create(['name' => 'Grandchild', 'slug' => 'grandchild']);
        $this->assertEquals(2, $grandchild->depth);
        $this->assertEquals('root/updated-child/grandchild', $grandchild->path);

        // Update root slug - should update entire subtree
        $root->slug = 'updated-root';
        $root->save();

        $child->refresh();
        $grandchild->refresh();

        $this->assertEquals('updated-root', $root->path);
        $this->assertEquals('updated-root/updated-child', $child->path);
        $this->assertEquals('updated-root/updated-child/grandchild', $grandchild->path);
    }
}
