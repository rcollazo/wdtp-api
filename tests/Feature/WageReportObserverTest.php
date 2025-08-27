<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use App\Models\WageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Cache;
use LevelUp\Experience\Models\ExperienceAudit;
use Tests\TestCase;

class WageReportObserverTest extends TestCase
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
    public function it_sets_sanity_score_and_status_on_creation()
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        // Create a wage report within normal bounds
        $wageReport = WageReport::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 1500, // $15.00/hour - reasonable wage
        ]);

        // Should have a sanity score and status set
        $this->assertNotNull($wageReport->sanity_score);
        $this->assertContains($wageReport->status, ['approved', 'pending']);
    }

    /** @test */
    public function it_automatically_approves_reasonable_wages()
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        // Create a wage report with reasonable wage
        $wageReport = WageReport::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 1500, // $15.00/hour
        ]);

        // Should be approved with non-negative sanity score
        $this->assertEquals('approved', $wageReport->status);
        $this->assertGreaterThanOrEqual(0, $wageReport->sanity_score);
    }

    /** @test */
    public function it_flags_outlier_wages_as_pending()
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        // Create a wage report with unreasonably high wage (but within model bounds)
        $wageReport = WageReport::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 19000, // $190.00/hour - high but within bounds
        ]);

        // Should be pending with negative sanity score
        $this->assertEquals('pending', $wageReport->status);
        $this->assertLessThan(0, $wageReport->sanity_score);
    }

    /** @test */
    public function it_increments_counters_for_approved_reports()
    {
        $organization = Organization::factory()->create(['wage_reports_count' => 0]);
        $location = Location::factory()->for($organization)->create(['wage_reports_count' => 0]);
        $user = User::factory()->create();

        // Create approved wage report
        WageReport::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 1500, // Reasonable wage - should be approved
        ]);

        // Refresh models from database
        $organization->refresh();
        $location->refresh();

        $this->assertEquals(1, $organization->wage_reports_count);
        $this->assertEquals(1, $location->wage_reports_count);
    }

    /** @test */
    public function it_does_not_increment_counters_for_pending_reports()
    {
        $organization = Organization::factory()->create(['wage_reports_count' => 0]);
        $location = Location::factory()->for($organization)->create(['wage_reports_count' => 0]);
        $user = User::factory()->create();

        // Force creation of pending report (bypass automatic approval)
        $wageReport = WageReport::factory()->make([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 19000, // High wage to force pending
        ]);

        // Manually set status to ensure it's pending
        $wageReport->status = 'pending';
        $wageReport->sanity_score = -5;
        $wageReport->save();

        // Refresh models from database
        $organization->refresh();
        $location->refresh();

        $this->assertEquals(0, $organization->wage_reports_count);
        $this->assertEquals(0, $location->wage_reports_count);
    }

    /** @test */
    public function it_awards_experience_points_for_approved_reports()
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        // Create approved wage report
        WageReport::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 1500, // Reasonable wage - should be approved
        ]);

        // User should have experience points
        $experiences = ExperienceAudit::where('user_id', $user->id)->get();

        $this->assertGreaterThan(0, $experiences->count());

        // Should have base submission XP
        $submissionXP = $experiences->where('reason', 'wage_report_submitted')->first();
        $this->assertNotNull($submissionXP);
        $this->assertEquals(10, $submissionXP->points);

        // Should have first report bonus
        $bonusXP = $experiences->where('reason', 'first_wage_report')->first();
        $this->assertNotNull($bonusXP);
        $this->assertEquals(25, $bonusXP->points);
    }

    /** @test */
    public function it_does_not_award_xp_for_pending_reports()
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        // Create report that should be pending
        $wageReport = WageReport::factory()->make([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 19000, // High wage to force pending
        ]);

        $wageReport->status = 'pending';
        $wageReport->sanity_score = -5;
        $wageReport->save();

        // User should not have experience points
        $experienceCount = ExperienceAudit::where('user_id', $user->id)->count();
        $this->assertEquals(0, $experienceCount);
    }

    /** @test */
    public function it_does_not_award_xp_for_anonymous_reports()
    {
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        // Create anonymous report (no user_id)
        WageReport::factory()->create([
            'user_id' => null,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 1500,
        ]);

        // No experience points should be created
        $this->assertEquals(0, ExperienceAudit::count());
    }

    /** @test */
    public function it_updates_counters_when_status_changes()
    {
        $organization = Organization::factory()->create(['wage_reports_count' => 0]);
        $location = Location::factory()->for($organization)->create(['wage_reports_count' => 0]);
        $user = User::factory()->create();

        // Create pending report by manually setting status
        $wageReport = WageReport::factory()->make([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 1500, // Normal wage
        ]);

        // Manually set to pending (bypassing observer logic)
        $wageReport->status = 'pending';
        $wageReport->sanity_score = 0;
        $wageReport->save();

        // Counters should be 0
        $organization->refresh();
        $location->refresh();
        $this->assertEquals(0, $organization->wage_reports_count);
        $this->assertEquals(0, $location->wage_reports_count);

        // Approve the report
        $wageReport->update(['status' => 'approved']);

        // Counters should be incremented
        $organization->refresh();
        $location->refresh();
        $this->assertEquals(1, $organization->wage_reports_count);
        $this->assertEquals(1, $location->wage_reports_count);

        // Reject the report
        $wageReport->update(['status' => 'rejected']);

        // Counters should be decremented back to 0
        $organization->refresh();
        $location->refresh();
        $this->assertEquals(0, $organization->wage_reports_count);
        $this->assertEquals(0, $location->wage_reports_count);
    }

    /** @test */
    public function it_decrements_counters_on_deletion()
    {
        $organization = Organization::factory()->create(['wage_reports_count' => 1]);
        $location = Location::factory()->for($organization)->create(['wage_reports_count' => 1]);
        $user = User::factory()->create();

        // Create approved report
        $wageReport = WageReport::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 1500, // Reasonable wage - should be approved
        ]);

        // Counters should be incremented (already at 1, so now 2)
        $organization->refresh();
        $location->refresh();
        $this->assertEquals(2, $organization->wage_reports_count);
        $this->assertEquals(2, $location->wage_reports_count);

        // Delete the report
        $wageReport->delete();

        // Counters should be decremented
        $organization->refresh();
        $location->refresh();
        $this->assertEquals(1, $organization->wage_reports_count);
        $this->assertEquals(1, $location->wage_reports_count);
    }

    /** @test */
    public function it_prevents_counter_underflow()
    {
        $organization = Organization::factory()->create(['wage_reports_count' => 0]);
        $location = Location::factory()->for($organization)->create(['wage_reports_count' => 0]);
        $user = User::factory()->create();

        // Create approved report
        $wageReport = WageReport::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 1500, // Reasonable wage - should be approved
        ]);

        // Manually set counters to 0 to test underflow protection
        $organization->update(['wage_reports_count' => 0]);
        $location->update(['wage_reports_count' => 0]);

        // Delete the report
        $wageReport->delete();

        // Counters should remain at 0 (no underflow)
        $organization->refresh();
        $location->refresh();
        $this->assertEquals(0, $organization->wage_reports_count);
        $this->assertEquals(0, $location->wage_reports_count);
    }

    /** @test */
    public function it_bumps_cache_versions_on_changes()
    {
        // Initialize cache versions
        Cache::put('wages:ver', 1);
        Cache::put('orgs:ver', 1);
        Cache::put('locations:ver', 1);

        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        // Create wage report
        WageReport::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 1500, // Reasonable wage
        ]);

        // Cache versions should be incremented
        $this->assertEquals(2, Cache::get('wages:ver'));
        $this->assertEquals(2, Cache::get('orgs:ver'));
        $this->assertEquals(2, Cache::get('locations:ver'));
    }

    /** @test */
    public function it_calculates_mad_score_with_location_statistics()
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        // Create several approved reports at location to build statistics
        $wages = [1000, 1100, 1200, 1300, 1400]; // $10-14/hour
        foreach ($wages as $wage) {
            WageReport::factory()->create([
                'organization_id' => $organization->id,
                'location_id' => $location->id,
                'wage_period' => 'hourly',
                'amount_cents' => $wage,
                'status' => 'approved',
            ]);
        }

        // Create a new report with similar wage (should be approved)
        $normalReport = WageReport::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 1250, // $12.50/hour - within range
        ]);

        $this->assertEquals('approved', $normalReport->status);
        $this->assertGreaterThanOrEqual(0, $normalReport->sanity_score);

        // Create a report with extreme wage (should be pending)
        $outlierReport = WageReport::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 3000, // $30.00/hour - above median but reasonable
        ]);

        $this->assertEquals('pending', $outlierReport->status);
        $this->assertLessThan(0, $outlierReport->sanity_score);
    }

    /** @test */
    public function it_handles_force_deleted_wage_reports()
    {
        $organization = Organization::factory()->create(['wage_reports_count' => 1]);
        $location = Location::factory()->for($organization)->create(['wage_reports_count' => 1]);
        $user = User::factory()->create();

        // Create approved report
        $wageReport = WageReport::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'status' => 'approved',
        ]);

        // Counters should be incremented
        $organization->refresh();
        $location->refresh();
        $this->assertEquals(2, $organization->wage_reports_count);
        $this->assertEquals(2, $location->wage_reports_count);

        // Force delete the report (since no soft deletes)
        $wageReport->forceDelete();

        // Counters should be decremented
        $organization->refresh();
        $location->refresh();
        $this->assertEquals(1, $organization->wage_reports_count);
        $this->assertEquals(1, $location->wage_reports_count);
    }

    /** @test */
    public function it_awards_first_report_bonus_only_once()
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        // Create first report
        WageReport::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 1500,
        ]);

        // Should have both base XP and first report bonus
        $experiences = ExperienceAudit::where('user_id', $user->id)->get();
        $this->assertEquals(2, $experiences->count());

        $bonusXP = $experiences->where('reason', 'first_wage_report');
        $this->assertEquals(1, $bonusXP->count());

        // Create second report
        WageReport::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 1600,
        ]);

        // Should have only one more base XP, no additional bonus
        $experiences = ExperienceAudit::where('user_id', $user->id)->get();
        $this->assertEquals(3, $experiences->count());

        $bonusXP = $experiences->where('reason', 'first_wage_report');
        $this->assertEquals(1, $bonusXP->count()); // Still just one bonus
    }

    /** @test */
    public function it_handles_observer_exceptions_gracefully()
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        // Test observer handles edge cases without throwing exceptions
        $wageReport = WageReport::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 1500,
        ]);

        // Delete location to test orphaned references
        $location->delete();

        // Should not throw exception when trying to update counters for deleted location
        $this->expectNotToPerformAssertions();
        $wageReport->delete();
    }

    /** @test */
    public function it_calculates_sanity_score_with_organization_fallback()
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $location1 = Location::factory()->for($organization)->create();
        $location2 = Location::factory()->for($organization)->create();

        // Create approved reports at organization level (different locations)
        $wages = [1200, 1300, 1400, 1500, 1600]; // $12-16/hour
        foreach ($wages as $wage) {
            WageReport::factory()->create([
                'organization_id' => $organization->id,
                'location_id' => $location1->id,
                'wage_period' => 'hourly',
                'amount_cents' => $wage,
                'status' => 'approved',
            ]);
        }

        // Create report at different location but same organization
        $normalReport = WageReport::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location2->id, // Different location
            'wage_period' => 'hourly',
            'amount_cents' => 1350, // Within org range
        ]);

        // Should use organization-level statistics for sanity scoring
        $this->assertNotNull($normalReport->sanity_score);
        $this->assertContains($normalReport->status, ['approved', 'pending']);
    }
}
