<?php

namespace Tests\Feature\Api\V1;

use App\Models\Industry;
use App\Models\Location;
use App\Models\Organization;
use App\Models\WageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WageReportApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $industry = Industry::factory()->create([
            'name' => 'Food Service',
            'slug' => 'food-service',
        ]);

        $this->organization = Organization::factory()->create([
            'name' => 'Test Restaurant',
            'slug' => 'test-restaurant',
            'primary_industry_id' => $industry->id,
        ]);

        $this->location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Location',
            'city' => 'New York',
            'state_province' => 'NY',
        ]);
    }

    public function test_can_list_approved_wage_reports(): void
    {
        // Create approved wage report
        $approvedReport = WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'job_title' => 'Server',
            'employment_type' => 'part_time',
            'wage_period' => 'hourly',
            'amount_cents' => 1500, // $15.00
            'currency' => 'USD',
        ]);

        // Create pending wage report (should not appear) - manually set status after creation
        $pendingReport = WageReport::factory()->make([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 1200, // $12.00/hour - reasonable wage
            'normalized_hourly_cents' => 1200,
            'status' => 'pending',
        ]);

        // Save without observer to maintain pending status
        WageReport::withoutEvents(function () use ($pendingReport) {
            $pendingReport->save();
        });

        $response = $this->getJson('/api/v1/wage-reports');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data') // Only approved report should appear
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'job_title',
                        'employment_type_display',
                        'normalized_hourly_money',
                        'currency',
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
                    ],
                ],
                'links',
                'meta',
            ]);

        // Check the approved report is included
        $response->assertJsonPath('data.0.id', $approvedReport->id)
            ->assertJsonPath('data.0.job_title', 'Server')
            ->assertJsonPath('data.0.employment_type_display', 'Part Time');
    }

    public function test_can_show_approved_wage_report(): void
    {
        $wageReport = WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'job_title' => 'Barista',
            'employment_type' => 'full_time',
            'wage_period' => 'hourly',
            'amount_cents' => 1800, // $18.00
            'currency' => 'USD',
        ]);

        $response = $this->getJson("/api/v1/wage-reports/{$wageReport->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'job_title',
                    'employment_type',
                    'employment_type_display',
                    'wage_period',
                    'wage_period_display',
                    'amount_cents',
                    'normalized_hourly_cents',
                    'currency',
                    'tips_included',
                    'location',
                    'organization',
                    'original_amount_money',
                    'normalized_hourly_money',
                ],
            ])
            ->assertJsonPath('data.id', $wageReport->id)
            ->assertJsonPath('data.job_title', 'Barista')
            ->assertJsonPath('data.employment_type_display', 'Full Time')
            ->assertJsonPath('data.normalized_hourly_money', '$18.00');
    }

    public function test_cannot_show_pending_wage_report(): void
    {
        $wageReport = WageReport::factory()->make([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 1200, // $12.00/hour - reasonable wage
            'normalized_hourly_cents' => 1200,
            'status' => 'pending',
        ]);

        // Save without observer to maintain pending status
        WageReport::withoutEvents(function () use ($wageReport) {
            $wageReport->save();
        });

        $response = $this->getJson("/api/v1/wage-reports/{$wageReport->id}");

        $response->assertStatus(404);
    }

    public function test_can_filter_wage_reports_by_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherLocation = Location::factory()->create(['organization_id' => $otherOrg->id]);

        // Create report for our test organization
        $ourReport = WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
        ]);

        // Create report for other organization
        WageReport::factory()->approved()->create([
            'organization_id' => $otherOrg->id,
            'location_id' => $otherLocation->id,
        ]);

        $response = $this->getJson("/api/v1/wage-reports?organization_id={$this->organization->id}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ourReport->id);
    }

    public function test_can_filter_wage_reports_by_job_title(): void
    {
        WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'job_title' => 'Server',
        ]);

        WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'job_title' => 'Cook',
        ]);

        $response = $this->getJson('/api/v1/wage-reports?job_title=Server');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.job_title', 'Server');
    }

    public function test_can_filter_wage_reports_by_wage_range(): void
    {
        // Create low wage report ($10/hour)
        WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 1000,
            'normalized_hourly_cents' => 1000,
        ]);

        // Create high wage report ($25/hour)
        WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 2500,
            'normalized_hourly_cents' => 2500,
        ]);

        // Filter for wages between $15-30/hour
        $response = $this->getJson('/api/v1/wage-reports?min_hr=15&max_hr=30');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data'); // Only high wage report should match
    }

    public function test_can_sort_wage_reports(): void
    {
        // Create reports with different wages
        $lowWage = WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'normalized_hourly_cents' => 1000,
        ]);

        $highWage = WageReport::factory()->approved()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'normalized_hourly_cents' => 2500,
        ]);

        // Test sorting by highest wage first
        $response = $this->getJson('/api/v1/wage-reports?sort=highest');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $highWage->id)
            ->assertJsonPath('data.1.id', $lowWage->id);

        // Test sorting by lowest wage first
        $response = $this->getJson('/api/v1/wage-reports?sort=lowest');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $lowWage->id)
            ->assertJsonPath('data.1.id', $highWage->id);
    }

    public function test_validates_coordinate_bounds_for_spatial_search(): void
    {
        $response = $this->getJson('/api/v1/wage-reports?near=100.0,-200.0');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['near']);
    }

    public function test_pagination_works(): void
    {
        // Create multiple wage reports with reasonable wages to ensure they get approved
        WageReport::factory()->count(30)->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'wage_period' => 'hourly',
            'amount_cents' => 1500, // $15.00/hour - reasonable wage will get positive sanity score
            'normalized_hourly_cents' => 1500,
        ]);

        $response = $this->getJson('/api/v1/wage-reports?per_page=10');

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonStructure([
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'total', 'per_page'],
            ]);
    }
}
