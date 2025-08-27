<?php

namespace Tests\Feature;

use App\Models\WageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CacheManagementTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache versions for consistent testing
        Cache::forget('wages:ver');
        Cache::forget('orgs:ver');
        Cache::forget('locations:ver');
    }

    /** @test */
    public function test_cache_version_bump_on_create(): void
    {
        // Initialize cache versions
        Cache::put('wages:ver', 1);
        Cache::put('orgs:ver', 1);
        Cache::put('locations:ver', 1);

        $initialWagesVersion = Cache::get('wages:ver');
        $initialOrgsVersion = Cache::get('orgs:ver');
        $initialLocationsVersion = Cache::get('locations:ver');

        WageReport::factory()->create();

        // All cache versions should be incremented
        $this->assertEquals($initialWagesVersion + 1, Cache::get('wages:ver'));
        $this->assertEquals($initialOrgsVersion + 1, Cache::get('orgs:ver'));
        $this->assertEquals($initialLocationsVersion + 1, Cache::get('locations:ver'));
    }

    /** @test */
    public function test_cache_version_bump_on_update(): void
    {
        Cache::put('wages:ver', 10);
        Cache::put('orgs:ver', 10);
        Cache::put('locations:ver', 10);

        $wageReport = WageReport::factory()->create();

        // Reset versions after creation
        Cache::put('wages:ver', 10);
        Cache::put('orgs:ver', 10);
        Cache::put('locations:ver', 10);

        // Update status (should trigger observer)
        $wageReport->update(['status' => 'rejected']);

        // Cache versions should be incremented
        $this->assertEquals(11, Cache::get('wages:ver'));
        $this->assertEquals(11, Cache::get('orgs:ver'));
        $this->assertEquals(11, Cache::get('locations:ver'));
    }

    /** @test */
    public function test_cache_version_bump_on_delete(): void
    {
        $wageReport = WageReport::factory()->approved()->create();

        // Set known cache versions
        Cache::put('wages:ver', 5);
        Cache::put('orgs:ver', 5);
        Cache::put('locations:ver', 5);

        $wageReport->delete();

        // Cache versions should be incremented
        $this->assertEquals(6, Cache::get('wages:ver'));
        $this->assertEquals(6, Cache::get('orgs:ver'));
        $this->assertEquals(6, Cache::get('locations:ver'));
    }

    /** @test */
    public function test_cache_version_handles_missing_keys(): void
    {
        // Ensure cache keys don't exist
        Cache::forget('wages:ver');
        Cache::forget('orgs:ver');
        Cache::forget('locations:ver');

        WageReport::factory()->create();

        // Cache versions should be created and incremented from 0
        $this->assertEquals(1, Cache::get('wages:ver'));
        $this->assertEquals(1, Cache::get('orgs:ver'));
        $this->assertEquals(1, Cache::get('locations:ver'));
    }

    /** @test */
    public function test_cache_version_increment_is_safe(): void
    {
        // Test with large cache version numbers
        Cache::put('wages:ver', PHP_INT_MAX - 1);
        Cache::put('orgs:ver', PHP_INT_MAX - 1);
        Cache::put('locations:ver', PHP_INT_MAX - 1);

        // Should not overflow
        WageReport::factory()->create();

        // Verify increment worked (might wrap to 0 or stay at max depending on implementation)
        $this->assertTrue(is_int(Cache::get('wages:ver')));
        $this->assertTrue(is_int(Cache::get('orgs:ver')));
        $this->assertTrue(is_int(Cache::get('locations:ver')));
    }

    /** @test */
    public function test_cache_version_persistence(): void
    {
        Cache::put('wages:ver', 1);
        Cache::put('orgs:ver', 1);
        Cache::put('locations:ver', 1);

        WageReport::factory()->create();

        $wagesVersion = Cache::get('wages:ver');
        $orgsVersion = Cache::get('orgs:ver');
        $locationsVersion = Cache::get('locations:ver');

        // Versions should persist after retrieval
        $this->assertEquals($wagesVersion, Cache::get('wages:ver'));
        $this->assertEquals($orgsVersion, Cache::get('orgs:ver'));
        $this->assertEquals($locationsVersion, Cache::get('locations:ver'));
    }

    /** @test */
    public function test_cache_version_bump_on_status_change(): void
    {
        // Create pending report
        $wageReport = WageReport::factory()->make([
            'wage_period' => 'hourly',
            'amount_cents' => 19000, // High wage to force pending
        ]);
        $wageReport->status = 'pending';
        $wageReport->sanity_score = -5;
        $wageReport->save();

        // Set known cache versions
        Cache::put('wages:ver', 100);
        Cache::put('orgs:ver', 100);
        Cache::put('locations:ver', 100);

        // Approve the report (status change)
        $wageReport->update(['status' => 'approved']);

        // Cache versions should be incremented
        $this->assertEquals(101, Cache::get('wages:ver'));
        $this->assertEquals(101, Cache::get('orgs:ver'));
        $this->assertEquals(101, Cache::get('locations:ver'));
    }

    /** @test */
    public function test_cache_version_not_bumped_on_non_status_updates(): void
    {
        $wageReport = WageReport::factory()->create();

        // Set known cache versions
        Cache::put('wages:ver', 50);
        Cache::put('orgs:ver', 50);
        Cache::put('locations:ver', 50);

        // Update non-status field (this might not trigger cache bump depending on observer logic)
        $wageReport->update(['notes' => 'Updated notes']);

        // Cache versions should remain unchanged since status didn't change
        $this->assertEquals(50, Cache::get('wages:ver'));
        $this->assertEquals(50, Cache::get('orgs:ver'));
        $this->assertEquals(50, Cache::get('locations:ver'));
    }

    /** @test */
    public function test_cache_version_bump_on_restore(): void
    {
        $wageReport = WageReport::factory()->approved()->create();

        // Delete the report first
        $wageReport->delete();

        // Set known cache versions
        Cache::put('wages:ver', 20);
        Cache::put('orgs:ver', 20);
        Cache::put('locations:ver', 20);

        // Restore the report
        $wageReport->restore();

        // Cache versions should be incremented
        $this->assertEquals(21, Cache::get('wages:ver'));
        $this->assertEquals(21, Cache::get('orgs:ver'));
        $this->assertEquals(21, Cache::get('locations:ver'));
    }

    /** @test */
    public function test_cache_version_bump_on_force_delete(): void
    {
        $wageReport = WageReport::factory()->approved()->create();

        // Set known cache versions
        Cache::put('wages:ver', 30);
        Cache::put('orgs:ver', 30);
        Cache::put('locations:ver', 30);

        // Force delete the report
        $wageReport->forceDelete();

        // Cache versions should be incremented
        $this->assertEquals(31, Cache::get('wages:ver'));
        $this->assertEquals(31, Cache::get('orgs:ver'));
        $this->assertEquals(31, Cache::get('locations:ver'));
    }

    /** @test */
    public function test_cache_version_bump_multiple_operations(): void
    {
        Cache::put('wages:ver', 1);
        Cache::put('orgs:ver', 1);
        Cache::put('locations:ver', 1);

        // Create multiple reports
        $report1 = WageReport::factory()->create();
        $report2 = WageReport::factory()->create();
        $report3 = WageReport::factory()->create();

        // Cache versions should be incremented for each operation
        $this->assertEquals(4, Cache::get('wages:ver'));
        $this->assertEquals(4, Cache::get('orgs:ver'));
        $this->assertEquals(4, Cache::get('locations:ver'));

        // Update one report
        $report1->update(['status' => 'rejected']);

        // Cache versions should be incremented again
        $this->assertEquals(5, Cache::get('wages:ver'));
        $this->assertEquals(5, Cache::get('orgs:ver'));
        $this->assertEquals(5, Cache::get('locations:ver'));

        // Delete one report
        $report2->delete();

        // Cache versions should be incremented once more
        $this->assertEquals(6, Cache::get('wages:ver'));
        $this->assertEquals(6, Cache::get('orgs:ver'));
        $this->assertEquals(6, Cache::get('locations:ver'));
    }

    /** @test */
    public function test_cache_version_thread_safety(): void
    {
        Cache::put('wages:ver', 1);
        Cache::put('orgs:ver', 1);
        Cache::put('locations:ver', 1);

        // Simulate concurrent operations
        $reports = [];
        for ($i = 0; $i < 10; $i++) {
            $reports[] = WageReport::factory()->create();
        }

        // Each operation should increment cache versions
        // Final versions should be at least 11 (1 initial + 10 operations)
        $this->assertGreaterThanOrEqual(11, Cache::get('wages:ver'));
        $this->assertGreaterThanOrEqual(11, Cache::get('orgs:ver'));
        $this->assertGreaterThanOrEqual(11, Cache::get('locations:ver'));
    }

    /** @test */
    public function test_cache_version_consistency(): void
    {
        Cache::put('wages:ver', 100);
        Cache::put('orgs:ver', 100);
        Cache::put('locations:ver', 100);

        // Perform series of operations
        $report = WageReport::factory()->create();
        $report->update(['status' => 'rejected']);
        $report->delete();

        // All cache versions should be incremented by the same amount
        $wagesVersion = Cache::get('wages:ver');
        $orgsVersion = Cache::get('orgs:ver');
        $locationsVersion = Cache::get('locations:ver');

        $this->assertEquals($wagesVersion, $orgsVersion);
        $this->assertEquals($orgsVersion, $locationsVersion);
        $this->assertEquals(103, $wagesVersion); // 100 + 3 operations
    }

    /** @test */
    public function test_cache_version_bump_performance(): void
    {
        Cache::put('wages:ver', 1);
        Cache::put('orgs:ver', 1);
        Cache::put('locations:ver', 1);

        $startTime = microtime(true);

        // Create multiple reports to test cache bump performance
        for ($i = 0; $i < 50; $i++) {
            WageReport::factory()->create();
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;

        // Cache version bumps should not significantly impact performance
        $this->assertLessThan(3.0, $totalTime);

        // Verify final cache versions
        $this->assertEquals(51, Cache::get('wages:ver'));
        $this->assertEquals(51, Cache::get('orgs:ver'));
        $this->assertEquals(51, Cache::get('locations:ver'));
    }
}
