<?php

namespace Tests\Feature;

use App\Models\Industry;
use App\Models\Location;
use App\Models\Organization;
use App\Models\PositionCategory;
use App\Models\User;
use App\Models\WageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WageReportCreationTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected User $user;

    protected Organization $organization;

    protected Location $location;

    protected Industry $industry;

    protected PositionCategory $positionCategory;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->industry = Industry::factory()->create([
            'name' => 'Food Service',
            'slug' => 'food-service',
        ]);

        $this->organization = Organization::factory()
            ->active()
            ->verified()
            ->create([
                'name' => 'Test Restaurant',
                'slug' => 'test-restaurant',
                'primary_industry_id' => $this->industry->id,
            ]);

        $this->location = Location::factory()
            ->newYork()
            ->active()
            ->verified()
            ->create([
                'name' => 'Test Restaurant - Manhattan',
                'organization_id' => $this->organization->id,
            ]);

        $this->positionCategory = PositionCategory::factory()
            ->active()
            ->create([
                'name' => 'Server',
                'slug' => 'server',
                'industry_id' => $this->industry->id,
            ]);

        $this->user = User::factory()->create();
    }

    public function test_anonymous_user_can_submit_wage_report(): void
    {
        $wageData = [
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'wage_amount' => 15.50,
            'wage_type' => 'hourly',
            'employment_type' => 'part_time',
            'tips_included' => true,
            'additional_notes' => 'Great workplace with flexible hours',
        ];

        $response = $this->postJson('/api/v1/wage-reports', $wageData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'job_title',
                    'employment_type',
                    'wage_period',
                    'amount_cents',
                    'normalized_hourly_cents',
                    'currency',
                    'tips_included',
                    'created_at',
                    'location' => [
                        'id',
                        'name',
                        'city',
                        'state_province',
                    ],
                    'organization' => [
                        'id',
                        'name',
                        'slug',
                    ],
                    'position_category' => [
                        'id',
                        'name',
                        'slug',
                    ],
                    'original_amount_money',
                    'normalized_hourly_money',
                ],
                'message',
            ]);

        // Verify data was stored correctly
        $this->assertDatabaseHas('wage_reports', [
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'amount_cents' => 1550, // $15.50 * 100
            'normalized_hourly_cents' => 1550, // same for hourly
            'wage_period' => 'hourly',
            'employment_type' => 'part_time',
            'tips_included' => true,
            'user_id' => null, // Anonymous submission
        ]);

        // Verify observer processed the report
        $wageReport = WageReport::first();
        $this->assertEquals($this->organization->id, $wageReport->organization_id);
        $this->assertNotNull($wageReport->sanity_score);
        $this->assertContains($wageReport->status, ['approved', 'pending']);
    }

    public function test_authenticated_user_can_submit_wage_report(): void
    {
        Sanctum::actingAs($this->user);

        $wageData = [
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'wage_amount' => 12.75,
            'wage_type' => 'hourly',
            'employment_type' => 'full_time',
            'hours_per_week' => 40,
            'years_experience' => 3,
            'effective_date' => '2024-08-01',
            'unionized' => false,
        ];

        $response = $this->postJson('/api/v1/wage-reports', $wageData);

        $response->assertStatus(201);

        // Verify user was associated with the report
        $this->assertDatabaseHas('wage_reports', [
            'user_id' => $this->user->id,
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'amount_cents' => 1275,
            'hours_per_week' => 40,
            'unionized' => false,
            'effective_date' => '2024-08-01',
        ]);
    }

    public function test_salary_conversion_to_hourly(): void
    {
        $wageData = [
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'wage_amount' => 52000, // $52,000 yearly
            'wage_type' => 'yearly',
            'employment_type' => 'full_time',
            'hours_per_week' => 40,
        ];

        $response = $this->postJson('/api/v1/wage-reports', $wageData);

        $response->assertStatus(201);

        // Verify normalization: $52,000 / (52 weeks * 40 hours) = $25/hour = 2500 cents
        $this->assertDatabaseHas('wage_reports', [
            'amount_cents' => 5200000, // $52,000 * 100
            'normalized_hourly_cents' => 2500, // $25/hour * 100
        ]);
    }

    public function test_duplicate_submission_prevented_for_authenticated_users(): void
    {
        Sanctum::actingAs($this->user);

        // First submission
        $wageData = [
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'wage_amount' => 15.00,
            'wage_type' => 'hourly',
            'employment_type' => 'part_time',
        ];

        $this->postJson('/api/v1/wage-reports', $wageData)->assertStatus(201);

        // Second submission (duplicate)
        $response = $this->postJson('/api/v1/wage-reports', $wageData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['duplicate']);
    }

    public function test_anonymous_users_can_submit_duplicates(): void
    {
        // First anonymous submission
        $wageData = [
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'wage_amount' => 15.00,
            'wage_type' => 'hourly',
            'employment_type' => 'part_time',
        ];

        $this->postJson('/api/v1/wage-reports', $wageData)->assertStatus(201);

        // Second anonymous submission (should be allowed)
        $response = $this->postJson('/api/v1/wage-reports', $wageData);

        $response->assertStatus(201);

        // Should have 2 wage reports in database
        $this->assertCount(2, WageReport::all());
    }

    public function test_validation_errors_for_required_fields(): void
    {
        $response = $this->postJson('/api/v1/wage-reports', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'location_id',
                'position_category_id',
                'wage_amount',
                'wage_type',
                'employment_type',
            ]);
    }

    public function test_validation_errors_for_invalid_data(): void
    {
        $response = $this->postJson('/api/v1/wage-reports', [
            'location_id' => 99999, // Non-existent location
            'position_category_id' => 99999, // Non-existent position
            'wage_amount' => 0, // Below minimum
            'wage_type' => 'invalid_type',
            'employment_type' => 'invalid_employment',
            'years_experience' => -1, // Negative
            'hours_per_week' => 200, // Above maximum
            'additional_notes' => str_repeat('a', 1001), // Too long
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'location_id',
                'position_category_id',
                'wage_amount',
                'wage_type',
                'employment_type',
                'years_experience',
                'hours_per_week',
                'additional_notes',
            ]);
    }

    public function test_validation_error_for_future_effective_date(): void
    {
        $response = $this->postJson('/api/v1/wage-reports', [
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'wage_amount' => 15.00,
            'wage_type' => 'hourly',
            'employment_type' => 'part_time',
            'effective_date' => '2099-12-31', // Future date
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['effective_date']);
    }

    public function test_wage_bounds_validation(): void
    {
        // Test extremely low wage
        $response = $this->postJson('/api/v1/wage-reports', [
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'wage_amount' => 1.00, // $1/hour is below minimum
            'wage_type' => 'hourly',
            'employment_type' => 'part_time',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['wage_amount']);

        // Test extremely high wage
        $response = $this->postJson('/api/v1/wage-reports', [
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'wage_amount' => 300.00, // $300/hour is above maximum
            'wage_type' => 'hourly',
            'employment_type' => 'full_time',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['wage_amount']);
    }

    public function test_inactive_position_category_validation(): void
    {
        $inactivePosition = PositionCategory::factory()
            ->create([
                'status' => 'inactive',
                'industry_id' => $this->industry->id,
            ]);

        $response = $this->postJson('/api/v1/wage-reports', [
            'location_id' => $this->location->id,
            'position_category_id' => $inactivePosition->id,
            'wage_amount' => 15.00,
            'wage_type' => 'hourly',
            'employment_type' => 'part_time',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['position_category_id']);
    }

    public function test_observer_integration_increments_counters(): void
    {
        $initialOrgCount = $this->organization->wage_reports_count;
        $initialLocationCount = $this->location->wage_reports_count;

        // Submit wage report that should get approved (reasonable wage)
        $this->postJson('/api/v1/wage-reports', [
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'wage_amount' => 15.00, // Reasonable wage
            'wage_type' => 'hourly',
            'employment_type' => 'part_time',
        ])->assertStatus(201);

        // Refresh models to get updated counts
        $this->organization->refresh();
        $this->location->refresh();

        // Verify counters were incremented (if report was approved)
        $wageReport = WageReport::first();
        if ($wageReport->status === 'approved') {
            $this->assertEquals($initialOrgCount + 1, $this->organization->wage_reports_count);
            $this->assertEquals($initialLocationCount + 1, $this->location->wage_reports_count);
        }
    }

    public function test_observer_awards_experience_points_to_authenticated_users(): void
    {
        Sanctum::actingAs($this->user);

        $initialXp = $this->user->getPoints();

        $this->postJson('/api/v1/wage-reports', [
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'wage_amount' => 15.00,
            'wage_type' => 'hourly',
            'employment_type' => 'part_time',
        ])->assertStatus(201);

        $this->user->refresh();

        // Verify XP was awarded (if report was approved)
        $wageReport = WageReport::first();
        if ($wageReport->status === 'approved') {
            $this->assertGreaterThan($initialXp, $this->user->getPoints());
        }
    }

    public function test_response_includes_all_relationships(): void
    {
        $response = $this->postJson('/api/v1/wage-reports', [
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'wage_amount' => 15.00,
            'wage_type' => 'hourly',
            'employment_type' => 'part_time',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'location' => [
                        'id',
                        'name',
                        'address_line_1',
                        'city',
                        'state_province',
                    ],
                    'organization' => [
                        'id',
                        'name',
                        'slug',
                    ],
                    'position_category' => [
                        'id',
                        'name',
                        'slug',
                        'description',
                    ],
                ],
            ]);

        // Verify relationship data is correct
        $responseData = $response->json('data');
        $this->assertEquals($this->location->id, $responseData['location']['id']);
        $this->assertEquals($this->organization->id, $responseData['organization']['id']);
        $this->assertEquals($this->positionCategory->id, $responseData['position_category']['id']);
    }

    public function test_error_handling_for_database_issues(): void
    {
        // Create invalid data that will cause database constraint violation
        // (wage_amount that will result in zero normalized_hourly_cents)
        $response = $this->postJson('/api/v1/wage-reports', [
            'location_id' => $this->location->id,
            'position_category_id' => $this->positionCategory->id,
            'wage_amount' => 0.001, // Very small amount
            'wage_type' => 'yearly',
            'employment_type' => 'part_time',
            'hours_per_week' => 1000, // Unrealistic hours
        ]);

        // Should return validation error rather than database error
        $response->assertStatus(422);
    }

    public function test_multiple_submissions_without_rate_limiting(): void
    {
        // Create multiple positions to avoid duplicate detection
        $positions = [];
        for ($i = 0; $i < 5; $i++) {
            $positions[] = PositionCategory::factory()->active()->create([
                'name' => 'Server Position Test '.$i.' '.uniqid(),
                'slug' => 'server-position-test-'.$i.'-'.uniqid(),
                'industry_id' => $this->industry->id,
            ]);
        }

        // Make multiple requests (should all succeed as rate limiting is removed)
        for ($i = 0; $i < 5; $i++) {
            $wageData = [
                'location_id' => $this->location->id,
                'position_category_id' => $positions[$i]->id,
                'wage_amount' => 15.00,
                'wage_type' => 'hourly',
                'employment_type' => 'part_time',
            ];

            $response = $this->postJson('/api/v1/wage-reports', $wageData);
            $response->assertStatus(201);
        }
    }

    public function test_authenticated_users_multiple_submissions(): void
    {
        Sanctum::actingAs($this->user);

        // Create multiple positions to avoid duplicate detection
        $positions = [];
        for ($i = 0; $i < 10; $i++) {
            $positions[] = PositionCategory::factory()->active()->create([
                'name' => 'Server Position Test '.$i.' '.uniqid(),
                'slug' => 'server-position-test-'.$i.'-'.uniqid(),
                'industry_id' => $this->industry->id,
            ]);
        }

        // Make multiple requests (should all succeed as rate limiting is removed)
        for ($i = 0; $i < 10; $i++) {
            $wageData = [
                'location_id' => $this->location->id,
                'position_category_id' => $positions[$i]->id,
                'wage_amount' => 15.00,
                'wage_type' => 'hourly',
                'employment_type' => 'part_time',
            ];

            $response = $this->postJson('/api/v1/wage-reports', $wageData);
            $response->assertStatus(201);
        }
    }
}
