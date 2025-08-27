<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\WageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ObserverPerformanceTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache versions
        Cache::forget('wages:ver');
        Cache::forget('orgs:ver');
        Cache::forget('locations:ver');
    }

    /** @test */
    public function test_observer_performance_under_load(): void
    {
        $startTime = microtime(true);

        // Create 100 wage reports rapidly
        for ($i = 0; $i < 100; $i++) {
            WageReport::factory()->create([
                'status' => 'approved',
            ]);
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;

        // Should complete in under 10 seconds (100ms per report max)
        $this->assertLessThan(10.0, $totalTime);
    }

    /** @test */
    public function test_single_observer_event_performance(): void
    {
        $location = Location::factory()->create();
        $organization = $location->organization;

        $startTime = microtime(true);

        WageReport::factory()->create([
            'location_id' => $location->id,
            'organization_id' => $organization->id,
            'status' => 'approved',
        ]);

        $endTime = microtime(true);
        $eventTime = $endTime - $startTime;

        // Single observer event should complete in under 100ms
        $this->assertLessThan(0.1, $eventTime);
    }

    /** @test */
    public function test_sanity_score_calculation_performance(): void
    {
        $location = Location::factory()->create();

        // Create baseline data for MAD calculation
        WageReport::factory()->count(10)->create([
            'location_id' => $location->id,
            'normalized_hourly_cents' => 1500,
            'status' => 'approved',
        ]);

        $startTime = microtime(true);

        // Create report that will trigger sanity score calculation
        WageReport::factory()->create([
            'location_id' => $location->id,
            'normalized_hourly_cents' => 2000,
        ]);

        $endTime = microtime(true);
        $calculationTime = $endTime - $startTime;

        // Sanity score calculation should complete quickly
        $this->assertLessThan(0.05, $calculationTime);
    }

    /** @test */
    public function test_bulk_operation_performance(): void
    {
        $location = Location::factory()->create();
        $organization = $location->organization;
        $initialLocationCount = $location->wage_reports_count;
        $initialOrgCount = $organization->wage_reports_count;

        $startTime = microtime(true);

        // Execute bulk creation using factory directly
        for ($i = 0; $i < 25; $i++) {
            WageReport::factory()->create([
                'location_id' => $location->id,
                'organization_id' => $organization->id,
                'status' => 'approved',
            ]);
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;

        // Bulk operations should be efficient
        $this->assertLessThan(5.0, $totalTime);

        // Verify counters are accurate after bulk operations
        $location->refresh();
        $organization->refresh();

        $this->assertEquals($initialLocationCount + 25, $location->wage_reports_count);
        $this->assertEquals($initialOrgCount + 25, $organization->wage_reports_count);
    }

    /** @test */
    public function test_database_query_optimization(): void
    {
        $location = Location::factory()->create();

        // Enable query logging
        DB::enableQueryLog();

        WageReport::factory()->create([
            'location_id' => $location->id,
            'status' => 'approved',
        ]);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Should not execute excessive queries
        $this->assertLessThan(15, count($queries));
    }

    /** @test */
    public function test_counter_update_optimization(): void
    {
        $location = Location::factory()->create();
        $organization = $location->organization;

        DB::enableQueryLog();

        // Create multiple reports
        for ($i = 0; $i < 5; $i++) {
            WageReport::factory()->create([
                'location_id' => $location->id,
                'organization_id' => $organization->id,
                'status' => 'approved',
            ]);
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Counter updates should be optimized (increment operations are efficient)
        $counterQueries = collect($queries)->filter(function ($query) {
            return str_contains(strtolower($query['query']), 'increment') ||
                   str_contains(strtolower($query['query']), 'update');
        });

        // Should have efficient counter updates
        $this->assertLessThan(20, $counterQueries->count());
    }

    /** @test */
    public function test_memory_usage_under_load(): void
    {
        $initialMemory = memory_get_usage();

        // Create many reports to test memory efficiency
        for ($i = 0; $i < 100; $i++) {
            WageReport::factory()->create([
                'status' => 'approved',
            ]);
        }

        $finalMemory = memory_get_usage();
        $memoryIncrease = $finalMemory - $initialMemory;

        // Memory usage should be reasonable (less than 50MB increase)
        $this->assertLessThan(50 * 1024 * 1024, $memoryIncrease);
    }

    /** @test */
    public function test_concurrent_observer_events(): void
    {
        $location = Location::factory()->create();
        $organization = $location->organization;
        $initialLocationCount = $location->wage_reports_count;
        $initialOrgCount = $organization->wage_reports_count;

        $startTime = microtime(true);

        // Simulate concurrent operations
        $reports = [];
        for ($i = 0; $i < 10; $i++) {
            $reports[] = WageReport::factory()->create([
                'location_id' => $location->id,
                'organization_id' => $organization->id,
                'status' => 'approved',
            ]);
        }

        // Immediately delete them
        foreach ($reports as $report) {
            $report->delete();
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;

        // Concurrent operations should complete efficiently
        $this->assertLessThan(2.0, $totalTime);

        // Counters should be back to initial values
        $location->refresh();
        $organization->refresh();

        $this->assertEquals($initialLocationCount, $location->wage_reports_count);
        $this->assertEquals($initialOrgCount, $organization->wage_reports_count);
    }

    /** @test */
    public function test_transaction_performance(): void
    {
        $location = Location::factory()->create();
        $organization = $location->organization;
        $initialLocationCount = $location->wage_reports_count;
        $initialOrgCount = $organization->wage_reports_count;

        $startTime = microtime(true);

        DB::transaction(function () use ($location, $organization) {
            for ($i = 0; $i < 15; $i++) {
                WageReport::factory()->create([
                    'location_id' => $location->id,
                    'organization_id' => $organization->id,
                    'status' => 'approved',
                ]);
            }
        });

        $endTime = microtime(true);
        $transactionTime = $endTime - $startTime;

        // Transaction should complete efficiently
        $this->assertLessThan(3.0, $transactionTime);

        // Verify all operations completed successfully
        $location->refresh();
        $organization->refresh();

        $this->assertEquals($initialLocationCount + 15, $location->wage_reports_count);
        $this->assertEquals($initialOrgCount + 15, $organization->wage_reports_count);
    }

    /** @test */
    public function test_observer_event_complexity(): void
    {
        // Create complex scenario with multiple locations
        $locations = Location::factory()->count(3)->create();
        $reportsPerLocation = 3;

        $startTime = microtime(true);

        // Create reports across multiple locations
        foreach ($locations as $location) {
            $initialCount = $location->wage_reports_count;

            for ($i = 0; $i < $reportsPerLocation; $i++) {
                WageReport::factory()->create([
                    'location_id' => $location->id,
                    'organization_id' => $location->organization_id,
                    'status' => 'approved',
                ]);
            }

            // Verify counter for this location
            $location->refresh();
            $this->assertEquals($initialCount + $reportsPerLocation, $location->wage_reports_count);
        }

        $endTime = microtime(true);
        $complexOperationTime = $endTime - $startTime;

        // Complex scenario should still perform well
        $this->assertLessThan(5.0, $complexOperationTime);
    }
}
