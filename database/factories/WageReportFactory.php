<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\WageReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WageReport>
 */
class WageReportFactory extends Factory
{
    /**
     * Common job titles by industry with typical wage ranges
     */
    private static array $jobTitlesByIndustry = [
        'food_service' => [
            ['title' => 'Server', 'min_hourly' => 300, 'max_hourly' => 2500], // $3-25/hr (tips)
            ['title' => 'Cashier', 'min_hourly' => 800, 'max_hourly' => 1800], // $8-18/hr
            ['title' => 'Cook', 'min_hourly' => 1200, 'max_hourly' => 2200], // $12-22/hr
            ['title' => 'Kitchen Manager', 'min_hourly' => 1800, 'max_hourly' => 3500], // $18-35/hr
            ['title' => 'Barista', 'min_hourly' => 900, 'max_hourly' => 1900], // $9-19/hr
            ['title' => 'Dishwasher', 'min_hourly' => 800, 'max_hourly' => 1600], // $8-16/hr
        ],
        'retail' => [
            ['title' => 'Sales Associate', 'min_hourly' => 900, 'max_hourly' => 2000], // $9-20/hr
            ['title' => 'Cashier', 'min_hourly' => 800, 'max_hourly' => 1700], // $8-17/hr
            ['title' => 'Stock Associate', 'min_hourly' => 1000, 'max_hourly' => 1800], // $10-18/hr
            ['title' => 'Department Manager', 'min_hourly' => 1600, 'max_hourly' => 2800], // $16-28/hr
            ['title' => 'Store Manager', 'min_hourly' => 2200, 'max_hourly' => 4500], // $22-45/hr
        ],
        'healthcare' => [
            ['title' => 'Medical Assistant', 'min_hourly' => 1400, 'max_hourly' => 2200], // $14-22/hr
            ['title' => 'Receptionist', 'min_hourly' => 1200, 'max_hourly' => 2000], // $12-20/hr
            ['title' => 'Nurse (RN)', 'min_hourly' => 2800, 'max_hourly' => 5000], // $28-50/hr
            ['title' => 'Pharmacy Technician', 'min_hourly' => 1500, 'max_hourly' => 2300], // $15-23/hr
        ],
        'general' => [
            ['title' => 'Customer Service Representative', 'min_hourly' => 1200, 'max_hourly' => 2200], // $12-22/hr
            ['title' => 'Administrative Assistant', 'min_hourly' => 1300, 'max_hourly' => 2400], // $13-24/hr
            ['title' => 'Security Guard', 'min_hourly' => 1100, 'max_hourly' => 2000], // $11-20/hr
            ['title' => 'Maintenance Worker', 'min_hourly' => 1400, 'max_hourly' => 2600], // $14-26/hr
        ],
    ];

    /**
     * Geographic wage multipliers (cost of living adjustments)
     */
    private static array $cityWageMultipliers = [
        'New York' => 1.4,
        'San Francisco' => 1.5,
        'Los Angeles' => 1.3,
        'Seattle' => 1.3,
        'Boston' => 1.25,
        'Chicago' => 1.15,
        'Austin' => 1.1,
        'Denver' => 1.1,
        'Phoenix' => 1.0,
        'Houston' => 0.95,
        'Dallas' => 0.95,
        'Charlotte' => 0.9,
        'Columbus' => 0.85,
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Select random job data
        $industryJobs = fake()->randomElement(self::$jobTitlesByIndustry);
        $jobData = fake()->randomElement($industryJobs);

        // Default to hourly wage for simplicity
        $wagePeriod = 'hourly';
        $amountCents = fake()->numberBetween($jobData['min_hourly'], $jobData['max_hourly']);

        // Generate employment context
        $employmentTypes = ['full_time', 'part_time', 'seasonal', 'contract'];
        $employmentType = fake()->randomElement($employmentTypes);

        $hoursPerWeek = match ($employmentType) {
            'full_time' => fake()->numberBetween(35, 40),
            'part_time' => fake()->numberBetween(15, 30),
            'seasonal' => fake()->numberBetween(20, 40),
            'contract' => fake()->numberBetween(10, 40),
            default => 40, // Default to full-time hours
        };

        // Generate effective date (within last 2 years, weighted toward recent)
        $effectiveDate = fake()->optional(0.8)->dateTimeBetween('-2 years', 'now');

        return [
            'user_id' => null, // Will be set by specific states if needed
            'organization_id' => null, // Will be derived from location
            'location_id' => Location::factory(),
            'job_title' => $jobData['title'],
            'employment_type' => $employmentType,
            'wage_period' => $wagePeriod,
            'currency' => 'USD',
            'amount_cents' => $amountCents,
            'hours_per_week' => $hoursPerWeek,
            'effective_date' => $effectiveDate?->format('Y-m-d'),
            'tips_included' => fake()->boolean(25), // 25% include tips
            'unionized' => fake()->boolean(15), // 15% unionized
            'source' => fake()->randomElement(['user', 'public_posting', 'employer_claim', 'other']),
            'status' => fake()->randomElement(['approved', 'pending', 'rejected']),
            'sanity_score' => fake()->numberBetween(-5, 5),
            'notes' => fake()->optional(0.2)->sentence(),
        ];
    }

    /**
     * Calculate wage amount for a given period based on base hourly rate
     */
    private function calculateAmountForPeriod(int $baseHourlyCents, string $period): int
    {
        return match ($period) {
            'hourly' => $baseHourlyCents,
            'weekly' => $baseHourlyCents * WageReport::DEFAULT_HOURS_PER_WEEK,
            'biweekly' => $baseHourlyCents * WageReport::DEFAULT_HOURS_PER_WEEK * 2,
            'monthly' => intval(($baseHourlyCents * WageReport::DEFAULT_HOURS_PER_WEEK * 52) / 12),
            'yearly' => $baseHourlyCents * WageReport::DEFAULT_HOURS_PER_WEEK * 52,
            'per_shift' => $baseHourlyCents * WageReport::DEFAULT_SHIFT_HOURS,
            default => throw new \InvalidArgumentException("Invalid period: {$period} (type: ".gettype($period).')'),
        };
    }

    /**
     * Apply geographic wage adjustment based on location
     */
    public function applyGeographicAdjustment(): static
    {
        return $this->afterMaking(function (WageReport $wageReport) {
            // Get the location city
            $location = $wageReport->location ?? Location::factory()->create();
            $cityMultiplier = self::$cityWageMultipliers[$location->city] ?? 1.0;

            // Adjust wage amount
            $adjustedAmount = intval($wageReport->amount_cents * $cityMultiplier);
            $wageReport->amount_cents = $adjustedAmount;
        });
    }

    /**
     * Create an approved wage report
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'sanity_score' => fake()->numberBetween(0, 5),
        ]);
    }

    /**
     * Create a pending wage report
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'sanity_score' => fake()->numberBetween(-2, 2),
        ]);
    }

    /**
     * Create a rejected wage report
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'sanity_score' => fake()->numberBetween(-5, -1),
            'notes' => fake()->sentence(),
        ]);
    }

    /**
     * Create a food service wage report
     */
    public function foodService(): static
    {
        $jobData = fake()->randomElement(self::$jobTitlesByIndustry['food_service']);
        $baseHourlyCents = fake()->numberBetween($jobData['min_hourly'], $jobData['max_hourly']);
        $period = fake()->randomElement(['hourly', 'weekly', 'per_shift']);
        $amountCents = $this->calculateAmountForPeriod($baseHourlyCents, $period);

        return $this->state(fn (array $attributes) => [
            'job_title' => $jobData['title'],
            'wage_period' => $period,
            'amount_cents' => $amountCents,
            'tips_included' => fake()->boolean(60), // Higher chance for food service
        ]);
    }

    /**
     * Create a retail wage report
     */
    public function retail(): static
    {
        $jobData = fake()->randomElement(self::$jobTitlesByIndustry['retail']);
        $baseHourlyCents = fake()->numberBetween($jobData['min_hourly'], $jobData['max_hourly']);
        $period = fake()->randomElement(['hourly', 'weekly']);
        $amountCents = $this->calculateAmountForPeriod($baseHourlyCents, $period);

        return $this->state(fn (array $attributes) => [
            'job_title' => $jobData['title'],
            'wage_period' => $period,
            'amount_cents' => $amountCents,
            'tips_included' => false,
        ]);
    }

    /**
     * Create a healthcare wage report
     */
    public function healthcare(): static
    {
        $jobData = fake()->randomElement(self::$jobTitlesByIndustry['healthcare']);
        $baseHourlyCents = fake()->numberBetween($jobData['min_hourly'], $jobData['max_hourly']);
        $period = fake()->randomElement(['hourly', 'yearly']);
        $amountCents = $this->calculateAmountForPeriod($baseHourlyCents, $period);

        return $this->state(fn (array $attributes) => [
            'job_title' => $jobData['title'],
            'wage_period' => $period,
            'amount_cents' => $amountCents,
            'employment_type' => fake()->randomElement(['full_time', 'part_time']),
            'tips_included' => false,
            'unionized' => fake()->boolean(40), // Higher union rate in healthcare
        ]);
    }

    /**
     * Create a high wage report (outlier)
     */
    public function highWage(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_cents' => fake()->numberBetween(5000, 15000), // $50-150/hour
            'wage_period' => 'hourly',
            'job_title' => fake()->randomElement(['Senior Manager', 'Director', 'Consultant', 'Specialist']),
            'sanity_score' => fake()->numberBetween(-1, 2),
        ]);
    }

    /**
     * Create a low wage report (potentially concerning)
     */
    public function lowWage(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_cents' => fake()->numberBetween(200, 700), // $2-7/hour
            'wage_period' => 'hourly',
            'sanity_score' => fake()->numberBetween(-5, -1),
            'status' => fake()->randomElement(['pending', 'rejected']),
        ]);
    }

    /**
     * Create a recent wage report (within last 30 days)
     */
    public function recent(): static
    {
        return $this->state(fn (array $attributes) => [
            'effective_date' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'created_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'updated_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ]);
    }

    /**
     * Create with tips included
     */
    public function withTips(): static
    {
        return $this->state(fn (array $attributes) => [
            'tips_included' => true,
        ]);
    }

    /**
     * Create without tips
     */
    public function withoutTips(): static
    {
        return $this->state(fn (array $attributes) => [
            'tips_included' => false,
        ]);
    }

    /**
     * Create for a unionized position
     */
    public function unionized(): static
    {
        return $this->state(fn (array $attributes) => [
            'unionized' => true,
        ]);
    }
}
