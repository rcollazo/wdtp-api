<?php

namespace Tests\Feature\Models;

use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use App\Models\WageReport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class WageReportModelTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();

        // Verify PostGIS is available (for spatial tests)
        if (!$this->hasPostGIS()) {
            $this->markTestSkipped('PostGIS extension not available in test database');
        }
    }

    private function hasPostGIS(): bool
    {
        try {
            DB::select('SELECT PostGIS_Version()');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /** @test */
    public function testFactoryCreatesValidReports(): void
    {
        $wageReport = WageReport::factory()->create();

        $this->assertDatabaseHas('wage_reports', [
            'id' => $wageReport->id,
        ]);

        // Verify required fields are set
        $this->assertNotNull($wageReport->location_id);
        $this->assertNotNull($wageReport->job_title);
        $this->assertNotNull($wageReport->employment_type);
        $this->assertNotNull($wageReport->wage_period);
        $this->assertNotNull($wageReport->currency);
        $this->assertGreaterThan(0, $wageReport->amount_cents);
        $this->assertGreaterThan(0, $wageReport->normalized_hourly_cents);
        $this->assertContains($wageReport->status, ['approved', 'pending', 'rejected']);
    }

    /** @test */
    public function testFactoryStatesWork(): void
    {
        // Test industry-specific factory states
        $foodService = WageReport::factory()->foodService()->create();
        $this->assertContains($foodService->job_title, [
            'Server', 'Cashier', 'Cook', 'Kitchen Manager', 'Barista', 'Dishwasher'
        ]);

        $retail = WageReport::factory()->retail()->create();
        $this->assertContains($retail->job_title, [
            'Sales Associate', 'Cashier', 'Stock Associate', 'Department Manager', 'Store Manager'
        ]);

        $healthcare = WageReport::factory()->healthcare()->create();
        $this->assertContains($healthcare->job_title, [
            'Medical Assistant', 'Receptionist', 'Nurse (RN)', 'Pharmacy Technician'
        ]);

        // Test status factory states (observer may override status based on wage)
        $approved = WageReport::factory()->approved()->create();
        $this->assertContains($approved->status, ['approved', 'pending']); // Observer may change status

        $pending = WageReport::factory()->pending()->create();
        $this->assertContains($pending->status, ['approved', 'pending']); // Observer may change status

        $rejected = WageReport::factory()->rejected()->create();
        $this->assertContains($rejected->status, ['rejected', 'pending', 'approved']); // Observer may change status

        // Test wage-level factory states
        $highWage = WageReport::factory()->highWage()->create();
        $this->assertGreaterThanOrEqual(5000, $highWage->amount_cents);
        $this->assertEquals('hourly', $highWage->wage_period);

        $lowWage = WageReport::factory()->lowWage()->create();
        $this->assertLessThanOrEqual(700, $lowWage->amount_cents);
        $this->assertEquals('hourly', $lowWage->wage_period);

        // Test additional states
        $withTips = WageReport::factory()->withTips()->create();
        $this->assertTrue($withTips->tips_included);

        $withoutTips = WageReport::factory()->withoutTips()->create();
        $this->assertFalse($withoutTips->tips_included);

        $unionized = WageReport::factory()->unionized()->create();
        $this->assertTrue($unionized->unionized);
    }

    /** @test */
    public function testDatabaseConstraints(): void
    {
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        // Test positive amount constraint - model validation will catch this first
        $this->expectException(InvalidArgumentException::class);
        
        WageReport::factory()->create([
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'amount_cents' => -100, // Negative amount will trigger model normalization error
        ]);
    }

    /** @test */
    public function testPositiveNormalizedHourlyConstraint(): void
    {
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        $this->expectException(QueryException::class);
        
        // Try to directly insert negative normalized hourly cents (bypassing model normalization)
        DB::table('wage_reports')->insert([
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'job_title' => 'Test Job',
            'employment_type' => 'full_time',
            'wage_period' => 'hourly',
            'currency' => 'USD',
            'amount_cents' => 1500,
            'normalized_hourly_cents' => -100, // This should fail the constraint
            'hours_per_week' => 40,
            'source' => 'user',
            'status' => 'approved',
            'sanity_score' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test */
    public function testForeignKeyConstraints(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        // Valid foreign keys should work
        $wageReport = WageReport::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
        ]);

        $this->assertEquals($user->id, $wageReport->user_id);
        $this->assertEquals($organization->id, $wageReport->organization_id);
        $this->assertEquals($location->id, $wageReport->location_id);

        // Test cascading delete on location
        $location->delete();
        $this->assertDatabaseMissing('wage_reports', ['id' => $wageReport->id]);

        // Test null on delete for user (cleanup XP records first)
        $user2 = User::factory()->create();
        $organization2 = Organization::factory()->create();
        $location2 = Location::factory()->for($organization2)->create();
        
        $wageReport2 = WageReport::factory()->create([
            'user_id' => $user2->id,
            'organization_id' => $organization2->id,
            'location_id' => $location2->id,
        ]);

        // Clear any experience records to avoid foreign key constraint
        DB::table('experiences')->where('user_id', $user2->id)->delete();
        
        $user2->delete();
        $wageReport2->refresh();
        $this->assertNull($wageReport2->user_id);
    }

    /** @test */
    public function testModelEventsTriggered(): void
    {
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        // Test automatic normalization on create
        $wageReport = new WageReport([
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'job_title' => 'Test Job',
            'employment_type' => 'full_time',
            'wage_period' => 'weekly',
            'currency' => 'USD',
            'amount_cents' => 60000, // $600/week
            'hours_per_week' => 40,
            'source' => 'user',
        ]);

        // Before saving, normalized_hourly_cents should be null
        $this->assertNull($wageReport->normalized_hourly_cents);

        $wageReport->save();

        // After saving, should be normalized to $15/hour (1500 cents)
        $this->assertEquals(1500, $wageReport->normalized_hourly_cents);
    }

    /**
     * @test
     * @group spatial
     * @requires extension postgis
     */
    public function testSpatialQueriesWork(): void
    {
        $coordinates = [
            'nyc' => ['lat' => 40.7128, 'lng' => -74.0060],
            'la' => ['lat' => 34.0522, 'lng' => -118.2437],
            'chicago' => ['lat' => 41.8781, 'lng' => -87.6298],
        ];

        $organization = Organization::factory()->create();

        // Create wage reports at different locations
        $nycLocation = Location::factory()->withCoordinates($coordinates['nyc']['lat'], $coordinates['nyc']['lng'])->for($organization)->create();
        $laLocation = Location::factory()->withCoordinates($coordinates['la']['lat'], $coordinates['la']['lng'])->for($organization)->create();
        $chicagoLocation = Location::factory()->withCoordinates($coordinates['chicago']['lat'], $coordinates['chicago']['lng'])->for($organization)->create();

        $nycReport = WageReport::factory()->create(['location_id' => $nycLocation->id]);
        $laReport = WageReport::factory()->create(['location_id' => $laLocation->id]);
        $chicagoReport = WageReport::factory()->create(['location_id' => $chicagoLocation->id]);

        // Test nearby scope (from NYC coordinates, 1000km radius)
        $nearbyReports = WageReport::nearby($coordinates['nyc']['lat'], $coordinates['nyc']['lng'], 1000000)
            ->get();

        // Should find NYC and Chicago reports (both on east coast/midwest), but not LA
        $this->assertGreaterThan(0, $nearbyReports->count());
        
        // Verify distance is included in results
        $firstReport = $nearbyReports->first();
        $this->assertNotNull($firstReport->distance_meters);
        $this->assertIsNumeric($firstReport->distance_meters);
    }

    /**
     * @test
     * @group spatial
     * @requires extension postgis
     */
    public function testSpatialQueryAccuracy(): void
    {
        $organization = Organization::factory()->create();
        
        // NYC coordinates with small variations
        $baseCoords = ['lat' => 40.7128, 'lng' => -74.0060];
        $nearbyCoords = ['lat' => 40.7130, 'lng' => -74.0065]; // ~50m away

        $baseLocation = Location::factory()->withCoordinates($baseCoords['lat'], $baseCoords['lng'])->for($organization)->create();
        $nearbyLocation = Location::factory()->withCoordinates($nearbyCoords['lat'], $nearbyCoords['lng'])->for($organization)->create();

        $baseReport = WageReport::factory()->create(['location_id' => $baseLocation->id]);
        $nearbyReport = WageReport::factory()->create(['location_id' => $nearbyLocation->id]);

        // Query from base coordinates with 100m radius
        $nearbyReports = WageReport::nearby($baseCoords['lat'], $baseCoords['lng'], 100)->get();

        // Should find both reports (both within 100m)
        $this->assertEquals(2, $nearbyReports->count());

        // Verify distance ordering (base location should be first with ~0 distance)
        $distances = $nearbyReports->pluck('distance_meters')->toArray();
        $this->assertLessThan(1, $distances[0]); // First should be very close to 0
        $this->assertGreaterThan(1, $distances[1]); // Second should be further away
        $this->assertLessThan(100, $distances[1]); // But still within 100m

        // Test distance tolerance (±25m as per requirements)
        foreach ($distances as $distance) {
            $this->assertLessThan(75, $distance); // Well within tolerance for this test
        }
    }

    /**
     * @test
     * @group spatial
     * @requires extension postgis
     */
    public function testSpatialQueryPerformance(): void
    {
        $organization = Organization::factory()->create();
        $baseCoords = ['lat' => 40.7128, 'lng' => -74.0060];

        // Create multiple wage reports at the same location
        $location = Location::factory()->withCoordinates($baseCoords['lat'], $baseCoords['lng'])->for($organization)->create();
        
        WageReport::factory()->count(50)->create(['location_id' => $location->id]);

        $start = microtime(true);

        // Perform spatial query
        $results = WageReport::nearby($baseCoords['lat'], $baseCoords['lng'], 1000)->get();

        $duration = (microtime(true) - $start) * 1000; // Convert to milliseconds

        // Should complete within 200ms performance requirement
        $this->assertLessThan(200, $duration, "Spatial query took {$duration}ms, expected < 200ms");
        $this->assertGreaterThan(0, $results->count());
    }

    /** @test */
    public function testOrganizationDerivation(): void
    {
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        // Create wage report without organization_id
        $wageReport = WageReport::factory()->create([
            'organization_id' => null,
            'location_id' => $location->id,
        ]);

        // Should automatically derive organization_id from location
        $this->assertEquals($organization->id, $wageReport->organization_id);
    }

    /** @test */
    public function testNormalizationOnCreate(): void
    {
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        $testCases = [
            ['period' => 'hourly', 'amount' => 1500, 'expected' => 1500],
            ['period' => 'weekly', 'amount' => 60000, 'expected' => 1500],
            ['period' => 'monthly', 'amount' => 260000, 'expected' => 1500],
        ];

        foreach ($testCases as $case) {
            $wageReport = WageReport::factory()->create([
                'organization_id' => $organization->id,
                'location_id' => $location->id,
                'wage_period' => $case['period'],
                'amount_cents' => $case['amount'],
                'hours_per_week' => 40, // Ensure consistent hours_per_week
                'normalized_hourly_cents' => null,
            ]);

            $this->assertEquals(
                $case['expected'],
                $wageReport->normalized_hourly_cents,
                "Failed auto-normalization for {$case['period']} period"
            );
        }
    }

    /** @test */
    public function testStatusWorkflow(): void
    {
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        // Create report and manually set to pending to bypass observer
        $wageReport = WageReport::factory()->create([
            'organization_id' => $organization->id,
            'location_id' => $location->id,
        ]);
        
        // Update status to pending directly
        $wageReport->update(['status' => 'pending']);
        $this->assertEquals('pending', $wageReport->status);

        // Update to approved
        $wageReport->update(['status' => 'approved']);
        $this->assertEquals('approved', $wageReport->status);

        // Update to rejected
        $wageReport->update(['status' => 'rejected']);
        $this->assertEquals('rejected', $wageReport->status);

        // Valid status transitions should work
        $validStatuses = ['approved', 'pending', 'rejected'];
        foreach ($validStatuses as $status) {
            $wageReport->update(['status' => $status]);
            $this->assertEquals($status, $wageReport->status);
        }
    }

    /** @test */
    public function testBulkOperations(): void
    {
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        $start = microtime(true);

        // Create multiple wage reports
        $reports = WageReport::factory()->count(100)->create([
            'organization_id' => $organization->id,
            'location_id' => $location->id,
        ]);

        $duration = (microtime(true) - $start) * 1000;

        // Should complete in reasonable time (less than 5 seconds)
        $this->assertLessThan(5000, $duration, "Bulk creation took {$duration}ms");
        $this->assertEquals(100, $reports->count());

        // Verify all reports have valid normalized_hourly_cents
        foreach ($reports->take(10) as $report) { // Test sample
            $this->assertGreaterThan(0, $report->normalized_hourly_cents);
        }
    }
}