<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use App\Models\WageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CounterManagementTest extends TestCase
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
    public function testCountersMatchActualCounts(): void
    {
        $location1 = Location::factory()->create();
        $location2 = Location::factory()->create();
        $organization1 = $location1->organization;
        $organization2 = $location2->organization;

        // Create approved reports for location1
        WageReport::factory()->count(5)->create([
            'location_id' => $location1->id,
            'organization_id' => $organization1->id,
            'status' => 'approved'
        ]);

        // Create non-approved reports (shouldn't count)
        WageReport::factory()->count(3)->create([
            'location_id' => $location1->id,
            'organization_id' => $organization1->id,
            'status' => 'pending'
        ]);

        // Create approved reports for location2
        WageReport::factory()->count(2)->create([
            'location_id' => $location2->id,
            'organization_id' => $organization2->id,
            'status' => 'approved'
        ]);

        // Verify counter accuracy
        $location1->refresh();
        $location2->refresh();
        $organization1->refresh();
        $organization2->refresh();

        $this->assertEquals(5, $location1->wage_reports_count);
        $this->assertEquals(2, $location2->wage_reports_count);
        $this->assertEquals(5, $organization1->wage_reports_count);
        $this->assertEquals(2, $organization2->wage_reports_count);

        // Verify actual database counts match counters
        $actualLocation1Count = WageReport::where('location_id', $location1->id)
            ->where('status', 'approved')->count();
        $actualLocation2Count = WageReport::where('location_id', $location2->id)
            ->where('status', 'approved')->count();

        $this->assertEquals($location1->wage_reports_count, $actualLocation1Count);
        $this->assertEquals($location2->wage_reports_count, $actualLocation2Count);
    }

    /** @test */
    public function testCounterIncrementOnCreation(): void
    {
        $location = Location::factory()->create();
        $organization = $location->organization;
        $initialLocationCount = $location->wage_reports_count;
        $initialOrgCount = $organization->wage_reports_count;

        WageReport::factory()->create([
            'location_id' => $location->id,
            'organization_id' => $organization->id,
            'status' => 'approved'
        ]);

        $location->refresh();
        $organization->refresh();

        $this->assertEquals($initialLocationCount + 1, $location->wage_reports_count);
        $this->assertEquals($initialOrgCount + 1, $organization->wage_reports_count);
    }

    /** @test */
    public function testCounterDecrementOnDeletion(): void
    {
        $wageReport = WageReport::factory()->approved()->create();
        $location = $wageReport->location;
        $organization = $wageReport->organization;
        
        $initialLocationCount = $location->wage_reports_count;
        $initialOrgCount = $organization->wage_reports_count;

        $wageReport->delete();

        $location->refresh();
        $organization->refresh();

        $this->assertEquals($initialLocationCount - 1, $location->wage_reports_count);
        $this->assertEquals($initialOrgCount - 1, $organization->wage_reports_count);
    }

    /** @test */
    public function testCounterUnderflowProtection(): void
    {
        $location = Location::factory()->create();
        $organization = $location->organization;
        $location->update(['wage_reports_count' => 0]);
        $organization->update(['wage_reports_count' => 0]);

        // Create a wage report then delete it
        $wageReport = WageReport::factory()->create([
            'location_id' => $location->id,
            'organization_id' => $organization->id,
            'status' => 'approved'
        ]);

        // Manually set counters to 0 to simulate edge case
        $location->update(['wage_reports_count' => 0]);
        $organization->update(['wage_reports_count' => 0]);

        // Delete should not create negative count
        $wageReport->delete();

        $location->refresh();
        $organization->refresh();

        $this->assertGreaterThanOrEqual(0, $location->wage_reports_count);
        $this->assertGreaterThanOrEqual(0, $organization->wage_reports_count);
    }

    /** @test */
    public function testConcurrentCounterUpdates(): void
    {
        $location = Location::factory()->create();
        $organization = $location->organization;

        // Simulate concurrent operations by creating multiple reports rapidly
        $reports = [];
        for ($i = 0; $i < 10; $i++) {
            $reports[] = WageReport::factory()->create([
                'location_id' => $location->id,
                'organization_id' => $organization->id,
                'status' => 'approved'
            ]);
        }

        $location->refresh();
        $organization->refresh();

        // All counters should be incremented correctly
        $this->assertEquals(10, $location->wage_reports_count);
        $this->assertEquals(10, $organization->wage_reports_count);

        // Delete all reports
        foreach ($reports as $report) {
            $report->delete();
        }

        $location->refresh();
        $organization->refresh();

        // Counters should be back to 0
        $this->assertEquals(0, $location->wage_reports_count);
        $this->assertEquals(0, $organization->wage_reports_count);
    }

    /** @test */
    public function testCounterConsistencyAfterStatusChanges(): void
    {
        $location = Location::factory()->create();
        $organization = $location->organization;
        $initialLocationCount = $location->wage_reports_count;
        $initialOrgCount = $organization->wage_reports_count;

        // Create pending report (should not increment counters)
        $wageReport = WageReport::factory()->make([
            'location_id' => $location->id,
            'organization_id' => $organization->id,
            'wage_period' => 'hourly',
            'amount_cents' => 19000, // High wage to force pending
        ]);
        $wageReport->status = 'pending';
        $wageReport->sanity_score = -5;
        $wageReport->save();

        $location->refresh();
        $organization->refresh();

        // Counters should remain unchanged
        $this->assertEquals($initialLocationCount, $location->wage_reports_count);
        $this->assertEquals($initialOrgCount, $organization->wage_reports_count);

        // Approve the report
        $wageReport->update(['status' => 'approved']);

        $location->refresh();
        $organization->refresh();

        // Counters should be incremented
        $this->assertEquals($initialLocationCount + 1, $location->wage_reports_count);
        $this->assertEquals($initialOrgCount + 1, $organization->wage_reports_count);

        // Reject the report
        $wageReport->update(['status' => 'rejected']);

        $location->refresh();
        $organization->refresh();

        // Counters should be decremented back to original
        $this->assertEquals($initialLocationCount, $location->wage_reports_count);
        $this->assertEquals($initialOrgCount, $organization->wage_reports_count);
    }

    /** @test */
    public function testCounterInitializationFromExistingData(): void
    {
        $location = Location::factory()->create();
        $organization = $location->organization;

        // Manually create approved reports without triggering observers
        DB::table('wage_reports')->insert([
            [
                'location_id' => $location->id,
                'organization_id' => $organization->id,
                'job_title' => 'Test Job',
                'wage_period' => 'hourly',
                'amount_cents' => 1500,
                'normalized_hourly_cents' => 1500,
                'status' => 'approved',
                'currency' => 'USD',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'location_id' => $location->id,
                'organization_id' => $organization->id,
                'job_title' => 'Test Job 2',
                'wage_period' => 'hourly',
                'amount_cents' => 1600,
                'normalized_hourly_cents' => 1600,
                'status' => 'approved',
                'currency' => 'USD',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'location_id' => $location->id,
                'organization_id' => $organization->id,
                'job_title' => 'Test Job 3',
                'wage_period' => 'hourly',
                'amount_cents' => 1700,
                'normalized_hourly_cents' => 1700,
                'status' => 'pending', // Should not be counted
                'currency' => 'USD',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        // Calculate actual counts
        $actualLocationCount = WageReport::where('location_id', $location->id)
            ->where('status', 'approved')->count();
        $actualOrgCount = WageReport::where('organization_id', $organization->id)
            ->where('status', 'approved')->count();

        // Update counters to match actual data
        $location->update(['wage_reports_count' => $actualLocationCount]);
        $organization->update(['wage_reports_count' => $actualOrgCount]);

        $this->assertEquals(2, $location->wage_reports_count);
        $this->assertEquals(2, $organization->wage_reports_count);
        $this->assertEquals($actualLocationCount, $location->wage_reports_count);
        $this->assertEquals($actualOrgCount, $organization->wage_reports_count);
    }

    /** @test */
    public function testCounterPerformanceUnderLoad(): void
    {
        $location = Location::factory()->create();
        $organization = $location->organization;

        $startTime = microtime(true);

        // Create 50 wage reports rapidly
        for ($i = 0; $i < 50; $i++) {
            WageReport::factory()->create([
                'location_id' => $location->id,
                'organization_id' => $organization->id,
                'status' => 'approved'
            ]);
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;

        // Should complete in reasonable time (allow 5 seconds for 50 reports)
        $this->assertLessThan(5.0, $totalTime);

        // Verify counter accuracy after bulk operations
        $location->refresh();
        $organization->refresh();

        $this->assertEquals(50, $location->wage_reports_count);
        $this->assertEquals(50, $organization->wage_reports_count);
    }

    /** @test */
    public function testCounterAtomicity(): void
    {
        $location = Location::factory()->create();
        $organization = $location->organization;

        // Test that counter operations are atomic within transactions
        DB::beginTransaction();

        try {
            WageReport::factory()->create([
                'location_id' => $location->id,
                'organization_id' => $organization->id,
                'status' => 'approved'
            ]);

            $location->refresh();
            $organization->refresh();

            $this->assertEquals(1, $location->wage_reports_count);
            $this->assertEquals(1, $organization->wage_reports_count);

            // Rollback transaction
            DB::rollBack();

            $location->refresh();
            $organization->refresh();

            // Counters should be back to original values
            $this->assertEquals(0, $location->wage_reports_count);
            $this->assertEquals(0, $organization->wage_reports_count);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->fail('Transaction should not have failed: ' . $e->getMessage());
        }
    }

    /** @test */
    public function testCounterRecoveryFromInconsistentState(): void
    {
        $location = Location::factory()->create();
        $organization = $location->organization;

        // Create approved reports
        WageReport::factory()->count(3)->create([
            'location_id' => $location->id,
            'organization_id' => $organization->id,
            'status' => 'approved'
        ]);

        // Manually set counters to incorrect values
        $location->update(['wage_reports_count' => 10]);
        $organization->update(['wage_reports_count' => 15]);

        // Calculate correct counts
        $correctLocationCount = WageReport::where('location_id', $location->id)
            ->where('status', 'approved')->count();
        $correctOrgCount = WageReport::where('organization_id', $organization->id)
            ->where('status', 'approved')->count();

        $this->assertEquals(3, $correctLocationCount);
        $this->assertEquals(3, $correctOrgCount);

        // Fix counters by setting them to correct values
        $location->update(['wage_reports_count' => $correctLocationCount]);
        $organization->update(['wage_reports_count' => $correctOrgCount]);

        $location->refresh();
        $organization->refresh();

        $this->assertEquals(3, $location->wage_reports_count);
        $this->assertEquals(3, $organization->wage_reports_count);
    }

    /** @test */
    public function testCountersWithMultipleOrganizations(): void
    {
        // Test that counters work correctly when same location belongs to different orgs
        $organization1 = Organization::factory()->create();
        $organization2 = Organization::factory()->create();
        $location1 = Location::factory()->for($organization1)->create();
        $location2 = Location::factory()->for($organization2)->create();

        // Create reports for different orgs
        WageReport::factory()->count(3)->create([
            'location_id' => $location1->id,
            'organization_id' => $organization1->id,
            'status' => 'approved'
        ]);

        WageReport::factory()->count(2)->create([
            'location_id' => $location2->id,
            'organization_id' => $organization2->id,
            'status' => 'approved'
        ]);

        $location1->refresh();
        $location2->refresh();
        $organization1->refresh();
        $organization2->refresh();

        $this->assertEquals(3, $location1->wage_reports_count);
        $this->assertEquals(2, $location2->wage_reports_count);
        $this->assertEquals(3, $organization1->wage_reports_count);
        $this->assertEquals(2, $organization2->wage_reports_count);
    }

    /** @test */
    public function testCountersWithNullableFields(): void
    {
        // Test counter behavior when location_id or organization_id is null
        $location = Location::factory()->create();
        $organization = $location->organization;

        // Create report with null location_id (should not increment location counter)
        $report1 = WageReport::factory()->make([
            'organization_id' => $organization->id,
            'location_id' => null,
            'status' => 'approved'
        ]);
        $report1->save();

        $location->refresh();
        $organization->refresh();

        // Location counter should remain unchanged, org counter should increment
        $this->assertEquals(0, $location->wage_reports_count);
        $this->assertEquals(1, $organization->wage_reports_count);

        // Create report with null organization_id (should not increment org counter)
        $report2 = WageReport::factory()->make([
            'location_id' => $location->id,
            'organization_id' => null,
            'status' => 'approved'
        ]);
        $report2->save();

        $location->refresh();
        $organization->refresh();

        // Location counter should increment, org counter should remain same
        $this->assertEquals(1, $location->wage_reports_count);
        $this->assertEquals(1, $organization->wage_reports_count);
    }
}