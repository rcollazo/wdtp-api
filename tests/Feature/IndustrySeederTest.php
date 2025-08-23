<?php

namespace Tests\Feature;

use App\Models\Industry;
use Database\Seeders\IndustrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndustrySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_gmb_aligned_taxonomy(): void
    {
        // Run the seeder
        $this->seed(IndustrySeeder::class);

        // Verify root categories were created
        $rootCategories = Industry::whereNull('parent_id')->orderBy('sort')->get();
        $this->assertCount(8, $rootCategories);

        $expectedRoots = [
            'restaurants' => 'Restaurants',
            'retail' => 'Retail',
            'health' => 'Health',
            'lodging' => 'Lodging',
            'automotive' => 'Automotive',
            'professional-services' => 'Professional Services',
            'beauty-spas' => 'Beauty & Spas',
            'home-services' => 'Home Services',
        ];

        foreach ($expectedRoots as $slug => $name) {
            $industry = $rootCategories->firstWhere('slug', $slug);
            $this->assertNotNull($industry, "Root industry '{$slug}' should exist");
            $this->assertEquals($name, $industry->name);
            $this->assertEquals(0, $industry->depth);
            $this->assertEquals($slug, $industry->path);
            $this->assertTrue($industry->is_active);
            $this->assertTrue($industry->visible_in_ui);
        }
    }

    public function test_seeder_creates_child_categories(): void
    {
        $this->seed(IndustrySeeder::class);

        // Verify child categories exist
        $childCategories = Industry::whereNotNull('parent_id')->get();
        $this->assertGreaterThan(0, $childCategories->count());

        // Test specific parent-child relationship
        $restaurants = Industry::where('slug', 'restaurants')->first();
        $this->assertNotNull($restaurants);

        $fastFood = Industry::where('slug', 'fast-food-restaurant')->first();
        $this->assertNotNull($fastFood);
        $this->assertEquals($restaurants->id, $fastFood->parent_id);
        $this->assertEquals(1, $fastFood->depth);
        $this->assertEquals('restaurants/fast-food-restaurant', $fastFood->path);
        $this->assertTrue($fastFood->is_active);
        $this->assertTrue($fastFood->visible_in_ui);
    }

    public function test_seeder_is_idempotent(): void
    {
        // Run seeder first time
        $this->seed(IndustrySeeder::class);
        $firstRunCount = Industry::count();
        $firstRunRoots = Industry::whereNull('parent_id')->count();

        // Run seeder second time
        $this->seed(IndustrySeeder::class);
        $secondRunCount = Industry::count();
        $secondRunRoots = Industry::whereNull('parent_id')->count();

        // Counts should be identical
        $this->assertEquals($firstRunCount, $secondRunCount);
        $this->assertEquals($firstRunRoots, $secondRunRoots);

        // Verify no duplicates created
        $duplicateSlugs = Industry::selectRaw('slug, COUNT(*) as slug_count')
            ->groupBy('slug')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->assertCount(0, $duplicateSlugs, 'No duplicate slugs should exist');
    }

    public function test_all_seeded_industries_have_required_properties(): void
    {
        $this->seed(IndustrySeeder::class);

        $allIndustries = Industry::all();
        $this->assertGreaterThan(0, $allIndustries->count());

        foreach ($allIndustries as $industry) {
            // All should be active and visible
            $this->assertTrue($industry->is_active, "Industry {$industry->slug} should be active");
            $this->assertTrue($industry->visible_in_ui, "Industry {$industry->slug} should be visible in UI");

            // All should have proper sort orders
            $this->assertGreaterThan(0, $industry->sort, "Industry {$industry->slug} should have positive sort order");
            $this->assertEquals(0, $industry->sort % 10, "Industry {$industry->slug} sort order should be multiple of 10");

            // Depth and path should be computed correctly
            if ($industry->parent_id === null) {
                $this->assertEquals(0, $industry->depth, "Root industry {$industry->slug} should have depth 0");
                $this->assertEquals($industry->slug, $industry->path, 'Root industry path should equal slug');
            } else {
                $this->assertEquals(1, $industry->depth, "Child industry {$industry->slug} should have depth 1");
                $this->assertStringContainsString('/', $industry->path, 'Child industry path should contain separator');
            }
        }
    }

    public function test_seeder_creates_expected_total_count(): void
    {
        $this->seed(IndustrySeeder::class);

        // Should have 8 root + 29 child = 37 total
        $totalCount = Industry::count();
        $rootCount = Industry::whereNull('parent_id')->count();
        $childCount = Industry::whereNotNull('parent_id')->count();

        $this->assertEquals(8, $rootCount, 'Should have exactly 8 root categories');
        $this->assertEquals(37, $totalCount, 'Should have exactly 37 total industries');
        $this->assertEquals(29, $childCount, 'Should have exactly 29 child categories');
    }

    public function test_parent_child_relationships_are_correct(): void
    {
        $this->seed(IndustrySeeder::class);

        // Test restaurants category has expected children
        $restaurants = Industry::where('slug', 'restaurants')->first();
        $restaurantChildren = Industry::where('parent_id', $restaurants->id)->pluck('slug')->toArray();

        $expectedRestaurantChildren = [
            'fast-food-restaurant',
            'coffee-shop',
            'pizza-restaurant',
            'sandwich-shop',
            'american-restaurant',
            'mexican-restaurant',
        ];

        $this->assertEquals(count($expectedRestaurantChildren), count($restaurantChildren));
        foreach ($expectedRestaurantChildren as $expectedChild) {
            $this->assertContains($expectedChild, $restaurantChildren);
        }

        // Test automotive category has expected children (including gas-station)
        $automotive = Industry::where('slug', 'automotive')->first();
        $automotiveChildren = Industry::where('parent_id', $automotive->id)->pluck('slug')->toArray();

        $expectedAutomotiveChildren = [
            'auto-repair-shop',
            'car-wash',
            'gas-station',
        ];

        $this->assertEquals(count($expectedAutomotiveChildren), count($automotiveChildren));
        foreach ($expectedAutomotiveChildren as $expectedChild) {
            $this->assertContains($expectedChild, $automotiveChildren);
        }
    }
}
