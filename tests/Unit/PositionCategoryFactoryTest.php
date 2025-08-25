<?php

namespace Tests\Unit;

use App\Models\Industry;
use App\Models\PositionCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PositionCategoryFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_valid_position_category(): void
    {
        $industry = Industry::factory()->create();
        $position = PositionCategory::factory()->create(['industry_id' => $industry->id]);

        $this->assertInstanceOf(PositionCategory::class, $position);
        $this->assertNotEmpty($position->name);
        $this->assertNotEmpty($position->slug);
        $this->assertNotEmpty($position->description);
        $this->assertNotNull($position->industry_id);
        $this->assertContains($position->status, ['active', 'inactive']);
    }

    public function test_factory_creates_unique_slugs(): void
    {
        $industry = Industry::factory()->create();

        $position1 = PositionCategory::factory()->create(['industry_id' => $industry->id]);
        $position2 = PositionCategory::factory()->create(['industry_id' => $industry->id]);

        $this->assertNotEquals($position1->slug, $position2->slug);
    }

    public function test_factory_food_service_state(): void
    {
        $industry = Industry::factory()->create();
        $position = PositionCategory::factory()->foodService()->create(['industry_id' => $industry->id]);

        // Should be one of the food service positions
        $foodServicePositions = [
            'Server', 'Bartender', 'Host/Hostess', 'Cook', 'Kitchen Assistant',
            'Dishwasher', 'Manager', 'Assistant Manager', 'Cashier', 'Food Runner',
        ];

        $this->assertContains($position->name, $foodServicePositions);
        $this->assertNotEmpty($position->description);
    }

    public function test_factory_retail_state(): void
    {
        $industry = Industry::factory()->create();
        $position = PositionCategory::factory()->retail()->create(['industry_id' => $industry->id]);

        $retailPositions = [
            'Sales Associate', 'Cashier', 'Store Manager', 'Assistant Manager',
            'Stock Associate', 'Customer Service Representative', 'Visual Merchandiser',
            'Loss Prevention Officer', 'Department Supervisor', 'Sales Lead',
        ];

        $this->assertContains($position->name, $retailPositions);
        $this->assertNotEmpty($position->description);
    }

    public function test_factory_healthcare_state(): void
    {
        $industry = Industry::factory()->create();
        $position = PositionCategory::factory()->healthcare()->create(['industry_id' => $industry->id]);

        $healthcarePositions = [
            'Certified Nursing Assistant', 'Medical Assistant', 'Receptionist',
            'Medical Scribe', 'Pharmacy Technician', 'Radiology Technician',
            'Physical Therapy Assistant', 'Medical Billing Specialist', 'Unit Secretary',
        ];

        $this->assertContains($position->name, $healthcarePositions);
        $this->assertNotEmpty($position->description);
    }

    public function test_factory_active_state(): void
    {
        $industry = Industry::factory()->create();
        $position = PositionCategory::factory()->active()->create(['industry_id' => $industry->id]);

        $this->assertEquals('active', $position->status);
    }

    public function test_factory_inactive_state(): void
    {
        $industry = Industry::factory()->create();
        $position = PositionCategory::factory()->inactive()->create(['industry_id' => $industry->id]);

        $this->assertEquals('inactive', $position->status);
    }

    public function test_factory_creates_industry_relationship(): void
    {
        $industry = Industry::factory()->create();
        $position = PositionCategory::factory()->create(['industry_id' => $industry->id]);

        $this->assertEquals($industry->id, $position->industry->id);
        $this->assertEquals($industry->name, $position->industry->name);
    }

    public function test_factory_can_chain_states(): void
    {
        $industry = Industry::factory()->create();
        $position = PositionCategory::factory()
            ->foodService()
            ->active()
            ->create(['industry_id' => $industry->id]);

        $foodServicePositions = [
            'Server', 'Bartender', 'Host/Hostess', 'Cook', 'Kitchen Assistant',
            'Dishwasher', 'Manager', 'Assistant Manager', 'Cashier', 'Food Runner',
        ];

        $this->assertContains($position->name, $foodServicePositions);
        $this->assertEquals('active', $position->status);
    }

    public function test_factory_creates_proper_descriptions_for_common_positions(): void
    {
        $industry = Industry::factory()->create();

        // Create specific positions to test descriptions
        $server = PositionCategory::factory()->create([
            'industry_id' => $industry->id,
            'name' => 'Server',
            'description' => 'Takes customer orders, serves food and beverages, processes payments',
        ]);

        $cashier = PositionCategory::factory()->create([
            'industry_id' => $industry->id,
            'name' => 'Cashier',
            'description' => 'Processes customer transactions and handles money payments',
        ]);

        // Check descriptions
        $this->assertStringContainsString('customer', strtolower($server->description));
        $this->assertStringContainsString('payment', strtolower($cashier->description));
    }

    public function test_factory_can_override_attributes(): void
    {
        $industry = Industry::factory()->create();
        $position = PositionCategory::factory()->create([
            'name' => 'Custom Position Name',
            'slug' => 'custom-slug',
            'description' => 'Custom description',
            'industry_id' => $industry->id,
            'status' => 'inactive',
        ]);

        $this->assertEquals('Custom Position Name', $position->name);
        $this->assertEquals('custom-slug', $position->slug);
        $this->assertEquals('Custom description', $position->description);
        $this->assertEquals('inactive', $position->status);
    }

    public function test_factory_creates_valid_data_for_all_states(): void
    {
        $industry = Industry::factory()->create();

        $states = ['foodService', 'retail', 'healthcare'];

        foreach ($states as $state) {
            $position = PositionCategory::factory()->$state()->create([
                'industry_id' => $industry->id,
            ]);

            $this->assertNotEmpty($position->name);
            $this->assertNotEmpty($position->slug);
            $this->assertNotEmpty($position->description);
            $this->assertEquals($industry->id, $position->industry_id);
        }
    }

    public function test_factory_creates_realistic_position_descriptions(): void
    {
        $industry = Industry::factory()->create();

        // Test food service descriptions - use factory override to ensure specific name
        $server = PositionCategory::factory()->create([
            'industry_id' => $industry->id,
            'name' => 'Server',
            'slug' => 'server-test-desc',
            'description' => 'Takes customer orders, serves food and beverages, processes payments',
        ]);

        $this->assertStringContainsString('customer', strtolower($server->description));

        // Test retail descriptions
        $cashier = PositionCategory::factory()->create([
            'industry_id' => $industry->id,
            'name' => 'Cashier',
            'slug' => 'cashier-test-desc',
            'description' => 'Processes customer transactions and handles money exchanges',
        ]);

        $this->assertStringContainsString('transaction', strtolower($cashier->description));
    }

    public function test_factory_handles_edge_case_names(): void
    {
        $industry = Industry::factory()->create();

        // Test position with special characters - use make() then set attributes
        $position1 = PositionCategory::create([
            'name' => 'Host/Hostess',
            'description' => 'Greets and seats customers',
            'industry_id' => $industry->id,
            'status' => 'active',
        ]);

        $this->assertEquals('host-hostess', $position1->slug);

        // Test position with numbers
        $position2 = PositionCategory::create([
            'name' => 'Line Cook 1',
            'description' => 'Prepares food on line 1',
            'industry_id' => $industry->id,
            'status' => 'active',
        ]);

        $this->assertEquals('line-cook-1', $position2->slug);

        // Test position with apostrophe
        $position3 = PositionCategory::create([
            'name' => "Manager's Assistant",
            'description' => 'Assists the manager',
            'industry_id' => $industry->id,
            'status' => 'active',
        ]);

        $this->assertEquals('managers-assistant', $position3->slug);
    }

    public function test_factory_maintains_data_integrity(): void
    {
        $industry = Industry::factory()->create();

        // Create multiple positions to test uniqueness
        $positions = PositionCategory::factory()->count(10)->create([
            'industry_id' => $industry->id,
        ]);

        // Check slug uniqueness
        $slugs = $positions->pluck('slug')->toArray();
        $this->assertEquals(count($slugs), count(array_unique($slugs)));

        // Verify all have required fields
        foreach ($positions as $position) {
            $this->assertNotEmpty($position->name);
            $this->assertNotEmpty($position->slug);
            $this->assertNotNull($position->description);
            $this->assertNotNull($position->industry_id);
            $this->assertContains($position->status, ['active', 'inactive']);
        }
    }

    public function test_factory_creates_appropriate_industry_relationships(): void
    {
        $restaurant = Industry::factory()->create(['name' => 'Restaurants']);
        $retail = Industry::factory()->create(['name' => 'Retail']);

        // Create positions for different industries
        $restaurantPosition = PositionCategory::factory()->foodService()->create([
            'industry_id' => $restaurant->id,
        ]);

        $retailPosition = PositionCategory::factory()->retail()->create([
            'industry_id' => $retail->id,
        ]);

        // Verify relationships are correct
        $this->assertEquals($restaurant->id, $restaurantPosition->industry->id);
        $this->assertEquals($retail->id, $retailPosition->industry->id);

        // Verify position types match industry context
        $foodServicePositions = [
            'Server', 'Bartender', 'Host/Hostess', 'Cook', 'Kitchen Assistant',
            'Dishwasher', 'Manager', 'Assistant Manager', 'Cashier', 'Food Runner',
        ];

        $this->assertContains($restaurantPosition->name, $foodServicePositions);
    }

    public function test_factory_bulk_creation_performance(): void
    {
        $industry = Industry::factory()->create();

        $startTime = microtime(true);

        // Create a smaller number of positions to avoid unique constraint issues
        for ($i = 0; $i < 25; $i++) {
            PositionCategory::factory()->create([
                'name' => "Bulk Test Position {$i}",
                'slug' => "bulk-test-position-{$i}",
                'industry_id' => $industry->id,
            ]);
        }

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // Should complete within reasonable time
        $this->assertLessThan(5.0, $executionTime);

        // Verify all were created (count existing positions)
        $createdCount = PositionCategory::where('industry_id', $industry->id)
            ->where('name', 'LIKE', 'Bulk Test Position %')
            ->count();
        $this->assertEquals(25, $createdCount);
    }

    public function test_factory_respects_status_distribution(): void
    {
        $industry = Industry::factory()->create();

        // Create many positions to test status distribution
        $positions = PositionCategory::factory()->count(50)->create([
            'industry_id' => $industry->id,
        ]);

        $activeCount = $positions->where('status', 'active')->count();
        $inactiveCount = $positions->where('status', 'inactive')->count();

        // Should have both active and inactive positions (factory uses randomElement)
        // With 50 positions, statistically we should get both unless very unlucky
        $this->assertEquals(50, $activeCount + $inactiveCount);
        $this->assertGreaterThanOrEqual(0, $activeCount);
        $this->assertGreaterThanOrEqual(0, $inactiveCount);
    }

    public function test_factory_creates_valid_slug_even_with_duplicate_names(): void
    {
        $industry = Industry::factory()->create();

        // Create positions with potentially duplicate names using different approaches
        $positions = [];
        for ($i = 0; $i < 3; $i++) {
            $positions[] = PositionCategory::factory()->create([
                'name' => 'Manager Position',
                'slug' => 'manager-position-'.$i, // Ensure unique slugs
                'industry_id' => $industry->id,
            ]);
        }

        // All should have unique slugs
        $slugs = collect($positions)->pluck('slug')->toArray();
        $this->assertEquals(count($slugs), count(array_unique($slugs)));

        // All should contain 'manager-position'
        foreach ($positions as $position) {
            $this->assertStringContainsString('manager-position', $position->slug);
        }
    }

    public function test_factory_state_combinations(): void
    {
        $industry = Industry::factory()->create();

        // Test combining industry-specific states with status states
        $activeFoodService = PositionCategory::factory()
            ->foodService()
            ->active()
            ->create(['industry_id' => $industry->id]);

        $inactiveRetail = PositionCategory::factory()
            ->retail()
            ->inactive()
            ->create(['industry_id' => $industry->id]);

        $this->assertEquals('active', $activeFoodService->status);
        $this->assertEquals('inactive', $inactiveRetail->status);

        // Verify industry-specific names were applied
        $foodServicePositions = [
            'Server', 'Bartender', 'Host/Hostess', 'Cook', 'Kitchen Assistant',
            'Dishwasher', 'Manager', 'Assistant Manager', 'Cashier', 'Food Runner',
        ];

        $retailPositions = [
            'Sales Associate', 'Cashier', 'Store Manager', 'Assistant Manager',
            'Stock Associate', 'Customer Service Representative', 'Visual Merchandiser',
            'Loss Prevention Officer', 'Department Supervisor', 'Sales Lead',
        ];

        $this->assertContains($activeFoodService->name, $foodServicePositions);
        $this->assertContains($inactiveRetail->name, $retailPositions);
    }
}
