<?php

namespace Tests\Unit\Models;

use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use App\Models\WageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use InvalidArgumentException;
use Tests\TestCase;

class WageReportTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /** @test */
    public function test_normalize_to_hourly_with_all_periods(): void
    {
        $testCases = [
            ['amount' => 1500, 'period' => 'hourly', 'expected' => 1500],
            ['amount' => 60000, 'period' => 'weekly', 'hours' => 40, 'expected' => 1500],
            ['amount' => 120000, 'period' => 'biweekly', 'hours' => 40, 'expected' => 1500],
            ['amount' => 520000, 'period' => 'monthly', 'hours' => 40, 'expected' => 3000],
            ['amount' => 3120000, 'period' => 'yearly', 'hours' => 40, 'expected' => 1500],
            ['amount' => 12000, 'period' => 'per_shift', 'shift_hours' => 8, 'expected' => 1500],
        ];

        foreach ($testCases as $case) {
            $result = WageReport::normalizeToHourly(
                $case['amount'],
                $case['period'],
                $case['hours'] ?? null,
                $case['shift_hours'] ?? null
            );

            $this->assertEquals(
                $case['expected'],
                $result,
                "Failed normalization for period {$case['period']}: expected {$case['expected']}, got {$result}"
            );
        }
    }

    /** @test */
    public function test_normalize_to_hourly_edge_cases(): void
    {
        // Test with default hours per week
        $result = WageReport::normalizeToHourly(60000, 'weekly');
        $this->assertEquals(1500, $result);

        // Test with default shift hours
        $result = WageReport::normalizeToHourly(12000, 'per_shift');
        $this->assertEquals(1500, $result);

        // Test minimum bounds
        $result = WageReport::normalizeToHourly(200, 'hourly');
        $this->assertEquals(200, $result);

        // Test maximum bounds
        $result = WageReport::normalizeToHourly(20000, 'hourly');
        $this->assertEquals(20000, $result);
    }

    /** @test */
    public function test_normalize_to_hourly_invalid_period(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid wage period: invalid');

        WageReport::normalizeToHourly(1500, 'invalid');
    }

    /** @test */
    public function test_normalize_to_hourly_out_of_bounds(): void
    {
        // Test below minimum
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Normalized hourly wage (100 cents) is outside acceptable range');

        WageReport::normalizeToHourly(100, 'hourly');
    }

    /** @test */
    public function test_normalize_to_hourly_above_maximum(): void
    {
        // Test above maximum
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Normalized hourly wage (25000 cents) is outside acceptable range');

        WageReport::normalizeToHourly(25000, 'hourly');
    }

    /** @test */
    public function test_model_relationships(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        $wageReport = WageReport::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
        ]);

        // Test user relationship
        $this->assertInstanceOf(User::class, $wageReport->user);
        $this->assertEquals($user->id, $wageReport->user->id);

        // Test organization relationship
        $this->assertInstanceOf(Organization::class, $wageReport->organization);
        $this->assertEquals($organization->id, $wageReport->organization->id);

        // Test location relationship
        $this->assertInstanceOf(Location::class, $wageReport->location);
        $this->assertEquals($location->id, $wageReport->location->id);
    }

    /** @test */
    public function test_scope_approved(): void
    {
        // Clear any existing data
        WageReport::truncate();

        // Create reports and manually update status to bypass observer
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        $approved = WageReport::factory()->create(['location_id' => $location->id]);
        $pending = WageReport::factory()->create(['location_id' => $location->id]);
        $rejected = WageReport::factory()->create(['location_id' => $location->id]);

        // Update statuses directly in database to bypass observer
        $approved->update(['status' => 'approved']);
        $pending->update(['status' => 'pending']);
        $rejected->update(['status' => 'rejected']);

        $approvedReports = WageReport::approved()->get();

        $this->assertEquals(1, $approvedReports->count());
        $this->assertEquals('approved', $approvedReports->first()->status);
    }

    /** @test */
    public function test_scope_by_job_title(): void
    {
        WageReport::factory()->create(['job_title' => 'Software Engineer']);
        WageReport::factory()->create(['job_title' => 'Senior Software Engineer']);
        WageReport::factory()->create(['job_title' => 'Server']);

        $results = WageReport::byJobTitle('Engineer')->get();

        $this->assertEquals(2, $results->count());
        foreach ($results as $report) {
            $this->assertStringContainsString('Engineer', $report->job_title);
        }
    }

    /** @test */
    public function test_scope_range(): void
    {
        WageReport::factory()->create(['normalized_hourly_cents' => 1000]); // $10.00/hour
        WageReport::factory()->create(['normalized_hourly_cents' => 1500]); // $15.00/hour
        WageReport::factory()->create(['normalized_hourly_cents' => 2000]); // $20.00/hour
        WageReport::factory()->create(['normalized_hourly_cents' => 2500]); // $25.00/hour

        $results = WageReport::range(1200, 2200)->get();

        $this->assertEquals(2, $results->count());
        foreach ($results as $report) {
            $this->assertGreaterThanOrEqual(1200, $report->normalized_hourly_cents);
            $this->assertLessThanOrEqual(2200, $report->normalized_hourly_cents);
        }
    }

    /** @test */
    public function test_scope_in_currency(): void
    {
        // Clear any existing data
        WageReport::truncate();

        WageReport::factory()->create(['currency' => 'USD']);
        WageReport::factory()->create(['currency' => 'CAD']);
        WageReport::factory()->create(['currency' => 'USD']); // Should be uppercase in factory

        $results = WageReport::inCurrency('usd')->get();

        $this->assertEquals(2, $results->count());
        foreach ($results as $report) {
            $this->assertEquals('USD', $report->currency);
        }
    }

    /** @test */
    public function test_scope_since(): void
    {
        WageReport::factory()->create(['effective_date' => '2024-01-01']);
        WageReport::factory()->create(['effective_date' => '2024-06-01']);
        WageReport::factory()->create(['effective_date' => '2024-12-01']);

        $results = WageReport::since('2024-07-01')->get();

        $this->assertEquals(1, $results->count());
        $this->assertEquals('2024-12-01', $results->first()->effective_date->format('Y-m-d'));
    }

    /** @test */
    public function test_scope_by_employment_type(): void
    {
        WageReport::factory()->create(['employment_type' => 'full_time']);
        WageReport::factory()->create(['employment_type' => 'part_time']);
        WageReport::factory()->create(['employment_type' => 'full_time']);

        $results = WageReport::byEmploymentType('full_time')->get();

        $this->assertEquals(2, $results->count());
        foreach ($results as $report) {
            $this->assertEquals('full_time', $report->employment_type);
        }
    }

    /** @test */
    public function test_helper_methods(): void
    {
        $wageReport = WageReport::factory()->make([
            'amount_cents' => 1550,
            'normalized_hourly_cents' => 1500,
        ]);

        // Test money formatting methods
        $this->assertEquals('$15.50', $wageReport->originalAmountMoney());
        $this->assertEquals('$15.00', $wageReport->normalizedHourlyMoney());
    }

    /** @test */
    public function test_outlier_detection(): void
    {
        // Test normal report (not outlier)
        $normalReport = WageReport::factory()->make(['sanity_score' => 2]);
        $this->assertFalse($normalReport->isOutlier());

        // Test outlier report
        $outlierReport = WageReport::factory()->make(['sanity_score' => -3]);
        $this->assertTrue($outlierReport->isOutlier());

        // Test boundary case
        $boundaryReport = WageReport::factory()->make(['sanity_score' => -2]);
        $this->assertFalse($boundaryReport->isOutlier());
    }

    /** @test */
    public function test_suspicious_wage_detection(): void
    {
        // Test normal wage
        $normalWage = WageReport::factory()->make(['normalized_hourly_cents' => 1500]);
        $this->assertFalse($normalWage->isSuspiciouslyHigh());
        $this->assertFalse($normalWage->isSuspiciouslyLow());

        // Test suspiciously high wage
        $highWage = WageReport::factory()->make(['normalized_hourly_cents' => 15000]); // $150/hour
        $this->assertTrue($highWage->isSuspiciouslyHigh());
        $this->assertFalse($highWage->isSuspiciouslyLow());

        // Test suspiciously low wage
        $lowWage = WageReport::factory()->make(['normalized_hourly_cents' => 600]); // $6/hour
        $this->assertFalse($lowWage->isSuspiciouslyHigh());
        $this->assertTrue($lowWage->isSuspiciouslyLow());

        // Test boundary cases
        $boundaryHigh = WageReport::factory()->make(['normalized_hourly_cents' => 10000]); // $100/hour
        $this->assertFalse($boundaryHigh->isSuspiciouslyHigh());

        $boundaryLow = WageReport::factory()->make(['normalized_hourly_cents' => 725]); // $7.25/hour
        $this->assertFalse($boundaryLow->isSuspiciouslyLow());
    }

    /** @test */
    public function test_display_attributes(): void
    {
        $wageReport = WageReport::factory()->make([
            'employment_type' => 'full_time',
            'wage_period' => 'hourly',
            'status' => 'approved',
        ]);

        $this->assertEquals('Full Time', $wageReport->getEmploymentTypeDisplayAttribute());
        $this->assertEquals('Hourly', $wageReport->getWagePeriodDisplayAttribute());
        $this->assertEquals('Approved', $wageReport->getStatusDisplayAttribute());

        // Test all employment types
        $employmentTypes = [
            'full_time' => 'Full Time',
            'part_time' => 'Part Time',
            'seasonal' => 'Seasonal',
            'contract' => 'Contract',
            'unknown' => 'Unknown',
        ];

        foreach ($employmentTypes as $type => $expected) {
            $report = WageReport::factory()->make(['employment_type' => $type]);
            $this->assertEquals($expected, $report->getEmploymentTypeDisplayAttribute());
        }

        // Test all wage periods
        $wagePeriods = [
            'hourly' => 'Hourly',
            'weekly' => 'Weekly',
            'biweekly' => 'Bi-weekly',
            'monthly' => 'Monthly',
            'yearly' => 'Yearly',
            'per_shift' => 'Per Shift',
            'unknown' => 'Unknown',
        ];

        foreach ($wagePeriods as $period => $expected) {
            $report = WageReport::factory()->make(['wage_period' => $period]);
            $this->assertEquals($expected, $report->getWagePeriodDisplayAttribute());
        }
    }

    /** @test */
    public function test_casts(): void
    {
        $wageReport = WageReport::factory()->create([
            'amount_cents' => 1500,
            'normalized_hourly_cents' => 1500,
            'hours_per_week' => 40,
            'effective_date' => '2024-01-15',
            'tips_included' => true,
            'unionized' => false,
            'sanity_score' => 5,
        ]);

        $this->assertIsInt($wageReport->amount_cents);
        $this->assertIsInt($wageReport->normalized_hourly_cents);
        $this->assertIsInt($wageReport->hours_per_week);
        $this->assertInstanceOf(\Carbon\Carbon::class, $wageReport->effective_date);
        $this->assertIsBool($wageReport->tips_included);
        $this->assertIsBool($wageReport->unionized);
        $this->assertIsInt($wageReport->sanity_score);
    }

    /** @test */
    public function test_fillable_attributes(): void
    {
        $fillableAttributes = [
            'user_id',
            'organization_id',
            'location_id',
            'job_title',
            'employment_type',
            'wage_period',
            'currency',
            'amount_cents',
            'normalized_hourly_cents',
            'hours_per_week',
            'effective_date',
            'tips_included',
            'unionized',
            'source',
            'status',
            'sanity_score',
            'notes',
        ];

        $wageReport = new WageReport;

        foreach ($fillableAttributes as $attribute) {
            $this->assertContains($attribute, $wageReport->getFillable());
        }

        // Test mass assignment with appropriate data types
        $data = [
            'user_id' => 1,
            'organization_id' => 1,
            'location_id' => 1,
            'job_title' => 'Test Job',
            'employment_type' => 'full_time',
            'wage_period' => 'hourly',
            'currency' => 'USD',
            'amount_cents' => 1500,
            'normalized_hourly_cents' => 1500,
            'hours_per_week' => 40,
            'effective_date' => '2024-01-01',
            'tips_included' => false,
            'unionized' => false,
            'source' => 'user',
            'status' => 'approved',
            'sanity_score' => 0,
            'notes' => 'Test notes',
        ];

        $wageReport = new WageReport($data);

        foreach ($fillableAttributes as $attribute) {
            $this->assertNotNull($wageReport->getAttribute($attribute), "Attribute {$attribute} should not be null");
        }
    }
}
