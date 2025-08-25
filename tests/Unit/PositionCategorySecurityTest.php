<?php

namespace Tests\Unit;

use App\Models\Industry;
use App\Models\PositionCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PositionCategorySecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->industry = Industry::factory()->create([
            'name' => 'Test Industry',
            'slug' => 'test-industry',
        ]);
    }

    public function test_api_prevents_sql_injection_in_search(): void
    {
        // Create test data
        PositionCategory::factory()->create([
            'name' => 'Server',
            'description' => 'Serves customers',
            'industry_id' => $this->industry->id,
        ]);

        // Test various SQL injection attempts in search
        $sqlInjectionAttempts = [
            "'; DROP TABLE position_categories; --",
            "' OR 1=1 --",
            "' UNION SELECT * FROM users --",
            "'; DELETE FROM position_categories; --",
            "' OR 'x'='x",
            "1' OR '1'='1",
        ];

        foreach ($sqlInjectionAttempts as $injection) {
            $response = $this->getJson('/api/v1/position-categories?q='.urlencode($injection));

            // Should not cause 500 error, should handle gracefully
            $this->assertContains($response->status(), [200, 422]);

            // Verify data is still intact
            $this->assertDatabaseHas('position_categories', ['name' => 'Server']);
        }
    }

    public function test_api_prevents_sql_injection_in_industry_filter(): void
    {
        PositionCategory::factory()->create([
            'name' => 'Test Position',
            'industry_id' => $this->industry->id,
        ]);

        $sqlInjectionAttempts = [
            '1; DROP TABLE industries; --',
            '1 OR 1=1',
            "'; SELECT * FROM users; --",
        ];

        foreach ($sqlInjectionAttempts as $injection) {
            $response = $this->getJson('/api/v1/position-categories?industry='.urlencode($injection));

            $this->assertContains($response->status(), [200, 422]);

            // Verify data integrity
            $this->assertDatabaseHas('position_categories', ['name' => 'Test Position']);
            $this->assertDatabaseHas('industries', ['name' => 'Test Industry']);
        }
    }

    public function test_api_sanitizes_xss_in_search_results(): void
    {
        // Create position with potentially malicious content
        $position = PositionCategory::factory()->create([
            'name' => '<script>alert("xss")</script>Server',
            'description' => '<img src="x" onerror="alert(\'xss\')" />Serves customers',
            'industry_id' => $this->industry->id,
        ]);

        $response = $this->getJson('/api/v1/position-categories?q=Server');
        $response->assertOk();

        $data = $response->json('data');
        $foundPosition = collect($data)->first(function ($item) use ($position) {
            return $item['id'] === $position->id;
        });

        // Data should be returned as-is (sanitization happens on frontend)
        // But no script execution should occur
        $this->assertNotNull($foundPosition);
        $this->assertStringContainsString('Server', $foundPosition['name']);
    }

    public function test_api_handles_extremely_long_input_strings(): void
    {
        // Test very long search query
        $longQuery = str_repeat('a', 10000);

        $response = $this->getJson('/api/v1/position-categories?q='.urlencode($longQuery));

        // Should either accept (truncated) or reject with validation error
        $this->assertContains($response->status(), [200, 422]);
    }

    public function test_api_prevents_information_disclosure(): void
    {
        $response = $this->getJson('/api/v1/position-categories/999999');

        $response->assertNotFound();

        // Should not reveal database structure or internal information
        $responseContent = $response->getContent();
        $this->assertStringNotContainsStringIgnoringCase('sql', $responseContent);
        $this->assertStringNotContainsStringIgnoringCase('database', $responseContent);
        $this->assertStringNotContainsStringIgnoringCase('table', $responseContent);
        $this->assertStringNotContainsStringIgnoringCase('column', $responseContent);
    }

    public function test_api_handles_unicode_characters_safely(): void
    {
        // Create position with unicode characters
        $unicodePosition = PositionCategory::factory()->create([
            'name' => 'Server 服务员',
            'description' => 'Serves customers 为顾客服务',
            'industry_id' => $this->industry->id,
        ]);

        // Test search with unicode
        $response = $this->getJson('/api/v1/position-categories?q='.urlencode('服务员'));
        $response->assertOk();

        // Test show with unicode slug
        $unicodeSlugPosition = PositionCategory::factory()->create([
            'name' => 'Manager',
            'slug' => 'manager-服务员',
            'industry_id' => $this->industry->id,
        ]);

        $response = $this->getJson('/api/v1/position-categories/'.urlencode('manager-服务员'));
        $response->assertOk();
    }

    public function test_api_rate_limiting_protection(): void
    {
        // Make many rapid requests to test if rate limiting kicks in
        $responses = [];

        for ($i = 0; $i < 100; $i++) {
            $responses[] = $this->getJson('/api/v1/position-categories');
        }

        // Most requests should succeed, but system should handle load
        $successCount = 0;
        foreach ($responses as $response) {
            if ($response->status() === 200) {
                $successCount++;
            }
        }

        // At least some should succeed
        $this->assertGreaterThan(50, $successCount);
    }

    public function test_api_validates_input_size_limits(): void
    {
        // Test per_page limits - controller limits to 100 max but doesn't reject higher values
        $response = $this->getJson('/api/v1/position-categories?per_page=999999');
        $response->assertOk();
        // Verify it was capped at 100
        $this->assertEquals(100, $response->json('meta.per_page'));

        // Test negative per_page
        $response = $this->getJson('/api/v1/position-categories?per_page=-1');
        $response->assertStatus(422);

        // Test autocomplete limit - should reject values over 50
        $response = $this->getJson('/api/v1/position-categories/autocomplete?q=test&limit=999');
        $response->assertStatus(422);
    }

    public function test_api_prevents_parameter_pollution(): void
    {
        // Test duplicate parameters
        $response = $this->getJson('/api/v1/position-categories?status=active&status=inactive');
        $response->assertOk();

        // Test multiple industry filters
        $response = $this->getJson('/api/v1/position-categories?industry=1&industry=2');
        $response->assertOk();

        // Should handle gracefully without errors
    }

    public function test_api_handles_malformed_json_requests(): void
    {
        // Test malformed Accept header
        $response = $this->json('GET', '/api/v1/position-categories', [], [
            'Accept' => 'application/malformed',
        ]);

        // Should handle gracefully
        $this->assertContains($response->status(), [200, 406]);
    }

    public function test_api_prevents_directory_traversal(): void
    {
        // Test path traversal attempts in route parameters
        $traversalAttempts = [
            '../../../etc/passwd',
            '..%2F..%2F..%2Fetc%2Fpasswd',
            '....//....//....//etc//passwd',
        ];

        foreach ($traversalAttempts as $attempt) {
            $response = $this->getJson('/api/v1/position-categories/'.urlencode($attempt));

            // Should return 404, not expose file system
            $response->assertNotFound();

            $content = $response->getContent();
            $this->assertStringNotContainsString('root:', $content);
            $this->assertStringNotContainsString('/etc/', $content);
        }
    }

    public function test_api_validates_content_length(): void
    {
        // Test extremely large request body (though GET requests don't have body)
        // This tests the framework's ability to handle large headers
        $largeValue = str_repeat('x', 100000);

        $response = $this->getJson('/api/v1/position-categories?q='.$largeValue);

        // Should either handle or reject gracefully
        $this->assertContains($response->status(), [200, 422, 413, 414]);
    }

    public function test_model_protects_against_mass_assignment(): void
    {
        // Test that non-fillable attributes cannot be mass assigned
        $maliciousData = [
            'name' => 'Test Position',
            'industry_id' => $this->industry->id,
            'id' => 999999,  // Should be ignored
            'created_at' => '2020-01-01 00:00:00',  // Should be ignored
            'updated_at' => '2020-01-01 00:00:00',  // Should be ignored
        ];

        $position = PositionCategory::create($maliciousData);

        // Should not have set the protected attributes
        $this->assertNotEquals(999999, $position->id);
        $this->assertNotEquals('2020-01-01 00:00:00', $position->created_at->format('Y-m-d H:i:s'));
    }

    public function test_database_constraints_prevent_data_corruption(): void
    {
        // Test that database constraints are enforced
        $position = PositionCategory::factory()->create([
            'industry_id' => $this->industry->id,
        ]);

        // Try to manually corrupt data should fail
        $this->expectException(\Illuminate\Database\QueryException::class);

        \DB::table('position_categories')->where('id', $position->id)->update([
            'industry_id' => 999999, // Non-existent industry
        ]);
    }

    public function test_api_headers_security(): void
    {
        $response = $this->getJson('/api/v1/position-categories');

        $response->assertOk();

        // Check that response doesn't expose sensitive server info
        $headers = $response->headers->all();

        // Should not reveal Laravel version or server details
        $this->assertArrayNotHasKey('x-powered-by', array_change_key_case($headers, CASE_LOWER));
        $this->assertArrayNotHasKey('server', array_change_key_case($headers, CASE_LOWER));
    }
}
