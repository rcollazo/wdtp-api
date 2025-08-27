<?php

namespace Tests\Feature;

use App\Models\Industry;
use App\Models\Location;
use App\Models\Organization;
use App\Models\PositionCategory;
use App\Models\User;
use App\Models\WageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WageReportThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Location $location;

    protected Industry $industry;

    protected array $positionCategories;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test industry
        $this->industry = Industry::factory()->create([
            'name' => 'Food Service',
            'slug' => 'food-service',
        ]);

        // Create test organization
        $this->organization = Organization::factory()
            ->active()
            ->verified()
            ->create([
                'name' => 'Test Restaurant Chain',
                'slug' => 'test-restaurant-chain',
                'primary_industry_id' => $this->industry->id,
            ]);

        // Create test location
        $this->location = Location::factory()
            ->newYork()
            ->active()
            ->verified()
            ->create([
                'name' => 'Test Restaurant - Manhattan',
                'organization_id' => $this->organization->id,
            ]);

        // Create multiple position categories to avoid duplicate validation
        $this->positionCategories = [];
        $positions = ['Server', 'Cook', 'Cashier', 'Manager', 'Barista', 'Host', 'Busser', 'Kitchen Prep'];

        foreach ($positions as $position) {
            for ($i = 1; $i <= 10; $i++) {
                $this->positionCategories[] = PositionCategory::factory()
                    ->active()
                    ->create([
                        'name' => $position.' '.$i,
                        'slug' => strtolower($position).'-'.$i,
                        'industry_id' => $this->industry->id,
                    ]);
            }
        }

        // Create test user
        $this->user = User::factory()->create();
    }

    public function test_anonymous_user_can_make_50_rapid_wage_report_requests_without_throttling(): void
    {
        $successCount = 0;
        $validationErrorCount = 0;
        $throttleCount = 0;
        $responses = [];

        // Make 50 rapid requests as anonymous user
        for ($i = 0; $i < 50; $i++) {
            $positionCategory = $this->positionCategories[$i];

            $wageData = [
                'location_id' => $this->location->id,
                'position_category_id' => $positionCategory->id,
                'wage_amount' => 15.50 + ($i * 0.25), // Vary wage amount to avoid issues
                'wage_type' => 'hourly',
                'employment_type' => $i % 2 === 0 ? 'part_time' : 'full_time',
                'tips_included' => $i % 3 === 0,
                'additional_notes' => "Test submission number {$i}",
            ];

            $response = $this->postJson('/api/v1/wage-reports', $wageData);
            $responses[] = [
                'request_number' => $i + 1,
                'status' => $response->getStatusCode(),
                'data' => $wageData,
            ];

            $statusCode = $response->getStatusCode();

            if ($statusCode === 201) {
                $successCount++;
                $response->assertJsonStructure([
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
                        'location',
                        'organization',
                        'position_category',
                        'original_amount_money',
                        'normalized_hourly_money',
                    ],
                    'message',
                ]);
            } elseif ($statusCode === 422) {
                $validationErrorCount++;
            } elseif ($statusCode === 429) {
                $throttleCount++;
            }
        }

        // Assert no throttling occurred
        $this->assertEquals(0, $throttleCount, 'Expected no 429 (Too Many Requests) responses, but got '.$throttleCount);

        // Assert we only got success or validation error responses
        $this->assertEquals(50, $successCount + $validationErrorCount,
            'Expected all 50 requests to return either 201 (success) or 422 (validation error)');

        // Assert majority of requests succeeded (should be most/all since we're using valid data)
        $this->assertGreaterThan(40, $successCount,
            'Expected at least 40 successful submissions with valid data');

        // Verify wage reports were actually created
        $this->assertGreaterThanOrEqual($successCount, WageReport::count());

        // Log results for debugging
        $this->addToAssertionCount(1); // Prevent risky test warning

        echo "\nAnonymous User Throttle Test Results:\n";
        echo "- Success (201): {$successCount}\n";
        echo "- Validation Error (422): {$validationErrorCount}\n";
        echo "- Throttled (429): {$throttleCount}\n";
        echo '- Total Wage Reports Created: '.WageReport::count()."\n";
    }

    public function test_authenticated_user_can_make_50_rapid_wage_report_requests_without_throttling(): void
    {
        Sanctum::actingAs($this->user);

        $successCount = 0;
        $validationErrorCount = 0;
        $duplicateErrorCount = 0;
        $throttleCount = 0;
        $responses = [];

        // Clear any existing wage reports for clean test
        WageReport::truncate();

        // Make 50 rapid requests as authenticated user
        for ($i = 0; $i < 50; $i++) {
            $positionCategory = $this->positionCategories[$i];

            $wageData = [
                'location_id' => $this->location->id,
                'position_category_id' => $positionCategory->id,
                'wage_amount' => 12.75 + ($i * 0.30), // Vary wage amount
                'wage_type' => 'hourly',
                'employment_type' => $i % 2 === 0 ? 'full_time' : 'part_time',
                'hours_per_week' => 30 + ($i % 20), // Vary hours
                'years_experience' => $i % 10, // Vary experience
                'effective_date' => '2024-08-01',
                'unionized' => $i % 4 === 0,
                'additional_notes' => "Authenticated test submission number {$i}",
            ];

            $response = $this->postJson('/api/v1/wage-reports', $wageData);
            $responses[] = [
                'request_number' => $i + 1,
                'status' => $response->getStatusCode(),
                'user_id' => $this->user->id,
            ];

            $statusCode = $response->getStatusCode();

            if ($statusCode === 201) {
                $successCount++;
                $response->assertJsonStructure([
                    'data' => [
                        'id',
                        'job_title',
                        'employment_type',
                        'wage_period',
                        'amount_cents',
                        'normalized_hourly_cents',
                        'currency',
                        'created_at',
                        'location',
                        'organization',
                        'position_category',
                        'original_amount_money',
                        'normalized_hourly_money',
                    ],
                    'message',
                ]);
            } elseif ($statusCode === 422) {
                $responseData = $response->json();
                if (isset($responseData['errors']['duplicate'])) {
                    $duplicateErrorCount++;
                } else {
                    $validationErrorCount++;
                }
            } elseif ($statusCode === 429) {
                $throttleCount++;
            }
        }

        // Assert no throttling occurred
        $this->assertEquals(0, $throttleCount, 'Expected no 429 (Too Many Requests) responses, but got '.$throttleCount);

        // Assert we only got expected response types
        $this->assertEquals(50, $successCount + $validationErrorCount + $duplicateErrorCount,
            'Expected all 50 requests to return either 201 (success), 422 (validation error), or 422 (duplicate error)');

        // Assert majority of requests succeeded
        $this->assertGreaterThan(40, $successCount,
            'Expected at least 40 successful submissions with valid, unique data');

        // Verify wage reports were created and associated with user
        $userReports = WageReport::where('user_id', $this->user->id)->count();
        $this->assertEquals($successCount, $userReports,
            'Number of wage reports created should match successful requests');

        // Log results for debugging
        $this->addToAssertionCount(1); // Prevent risky test warning

        echo "\nAuthenticated User Throttle Test Results:\n";
        echo "- Success (201): {$successCount}\n";
        echo "- Validation Error (422): {$validationErrorCount}\n";
        echo "- Duplicate Error (422): {$duplicateErrorCount}\n";
        echo "- Throttled (429): {$throttleCount}\n";
        echo "- Total User Wage Reports: {$userReports}\n";
    }

    public function test_mixed_valid_and_invalid_requests_without_throttling(): void
    {
        $validSuccessCount = 0;
        $validationErrorCount = 0;
        $throttleCount = 0;

        // Make 25 rapid requests with valid data and 25 with invalid data
        for ($i = 0; $i < 50; $i++) {
            if ($i < 25) {
                // Valid data requests
                $positionCategory = $this->positionCategories[$i];

                $wageData = [
                    'location_id' => $this->location->id,
                    'position_category_id' => $positionCategory->id,
                    'wage_amount' => 18.00,
                    'wage_type' => 'hourly',
                    'employment_type' => 'part_time',
                    'additional_notes' => "Valid test submission {$i}",
                ];
            } else {
                // Invalid data requests (missing required fields)
                $wageData = [
                    'location_id' => 99999, // Non-existent location
                    'wage_amount' => -5.00, // Invalid wage
                    'wage_type' => 'invalid_type',
                    'employment_type' => 'invalid_employment',
                ];
            }

            $response = $this->postJson('/api/v1/wage-reports', $wageData);
            $statusCode = $response->getStatusCode();

            if ($statusCode === 201) {
                $validSuccessCount++;
            } elseif ($statusCode === 422) {
                $validationErrorCount++;
                // Verify validation structure for invalid requests
                if ($i >= 25) {
                    $response->assertJsonStructure(['message', 'errors']);
                }
            } elseif ($statusCode === 429) {
                $throttleCount++;
            }
        }

        // Assert no throttling occurred for mixed requests
        $this->assertEquals(0, $throttleCount, 'Expected no 429 responses for mixed valid/invalid requests');

        // Assert appropriate responses for valid vs invalid data
        $this->assertEquals(25, $validSuccessCount, 'Expected 25 valid requests to succeed');
        $this->assertEquals(25, $validationErrorCount, 'Expected 25 invalid requests to return validation errors');

        // Verify correct number of reports created
        $this->assertEquals($validSuccessCount, WageReport::count());

        echo "\nMixed Valid/Invalid Requests Test Results:\n";
        echo "- Valid Success (201): {$validSuccessCount}\n";
        echo "- Validation Error (422): {$validationErrorCount}\n";
        echo "- Throttled (429): {$throttleCount}\n";
    }

    public function test_rapid_requests_from_multiple_user_contexts_without_throttling(): void
    {
        $anonymousSuccessCount = 0;
        $authenticatedSuccessCount = 0;
        $validationErrorCount = 0;
        $throttleCount = 0;

        // Create additional test users
        $users = User::factory()->count(3)->create();

        // Make alternating requests: anonymous, user1, user2, user3, anonymous, etc.
        for ($i = 0; $i < 40; $i++) {
            $positionCategory = $this->positionCategories[$i];

            // Alternate between anonymous and authenticated contexts
            if ($i % 4 === 0) {
                // Anonymous request
                $this->app['auth']->forgetGuards(); // Clear authentication
            } else {
                // Authenticated request (cycle through users)
                $userIndex = ($i % 4) - 1;
                Sanctum::actingAs($users[$userIndex]);
            }

            $wageData = [
                'location_id' => $this->location->id,
                'position_category_id' => $positionCategory->id,
                'wage_amount' => 16.00 + ($i * 0.15),
                'wage_type' => 'hourly',
                'employment_type' => 'part_time',
                'tips_included' => $i % 2 === 0,
                'additional_notes' => "Multi-context test {$i}",
            ];

            $response = $this->postJson('/api/v1/wage-reports', $wageData);
            $statusCode = $response->getStatusCode();

            if ($statusCode === 201) {
                if ($i % 4 === 0) {
                    $anonymousSuccessCount++;
                } else {
                    $authenticatedSuccessCount++;
                }
            } elseif ($statusCode === 422) {
                $validationErrorCount++;
            } elseif ($statusCode === 429) {
                $throttleCount++;
            }
        }

        // Assert no throttling occurred across different user contexts
        $this->assertEquals(0, $throttleCount, 'Expected no 429 responses across multiple user contexts');

        // Assert reasonable success rates
        $totalSuccess = $anonymousSuccessCount + $authenticatedSuccessCount;
        $this->assertGreaterThan(35, $totalSuccess, 'Expected most requests to succeed across contexts');
        $this->assertEquals(40, $totalSuccess + $validationErrorCount, 'All requests should return 201 or 422');

        // Verify reports were created
        $this->assertEquals($totalSuccess, WageReport::count());

        echo "\nMultiple User Contexts Test Results:\n";
        echo "- Anonymous Success: {$anonymousSuccessCount}\n";
        echo "- Authenticated Success: {$authenticatedSuccessCount}\n";
        echo "- Validation Errors: {$validationErrorCount}\n";
        echo "- Throttled: {$throttleCount}\n";
    }

    public function test_system_performance_under_rapid_wage_report_load(): void
    {
        $startTime = microtime(true);
        $responseTimes = [];
        $successCount = 0;
        $throttleCount = 0;

        // Measure response times for 30 rapid requests
        for ($i = 0; $i < 30; $i++) {
            $positionCategory = $this->positionCategories[$i];

            $requestStart = microtime(true);

            $wageData = [
                'location_id' => $this->location->id,
                'position_category_id' => $positionCategory->id,
                'wage_amount' => 17.25,
                'wage_type' => 'hourly',
                'employment_type' => 'full_time',
                'hours_per_week' => 40,
            ];

            $response = $this->postJson('/api/v1/wage-reports', $wageData);

            $requestEnd = microtime(true);
            $responseTime = ($requestEnd - $requestStart) * 1000; // Convert to milliseconds
            $responseTimes[] = $responseTime;

            $statusCode = $response->getStatusCode();
            if ($statusCode === 201) {
                $successCount++;
            } elseif ($statusCode === 429) {
                $throttleCount++;
            }
        }

        $totalTime = (microtime(true) - $startTime) * 1000; // Total time in milliseconds
        $averageResponseTime = array_sum($responseTimes) / count($responseTimes);
        $maxResponseTime = max($responseTimes);
        $minResponseTime = min($responseTimes);

        // Assert no throttling occurred during performance test
        $this->assertEquals(0, $throttleCount, 'Performance test should not trigger throttling');

        // Assert reasonable performance (all responses under 2 seconds)
        $this->assertLessThan(2000, $maxResponseTime, 'Maximum response time should be under 2 seconds');
        $this->assertLessThan(1000, $averageResponseTime, 'Average response time should be under 1 second');

        // Assert most requests succeeded
        $this->assertGreaterThan(25, $successCount, 'Most performance test requests should succeed');

        echo "\nPerformance Test Results:\n";
        echo '- Total Time: '.round($totalTime, 2)."ms\n";
        echo '- Average Response Time: '.round($averageResponseTime, 2)."ms\n";
        echo '- Min Response Time: '.round($minResponseTime, 2)."ms\n";
        echo '- Max Response Time: '.round($maxResponseTime, 2)."ms\n";
        echo "- Success Count: {$successCount}/30\n";
        echo "- Throttled: {$throttleCount}\n";
    }
}
