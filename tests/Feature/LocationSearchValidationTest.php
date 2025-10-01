<?php

namespace Tests\Feature;

use Tests\TestCase;

class LocationSearchValidationTest extends TestCase
{
    /**
     * Test that valid parameters pass validation.
     */
    public function test_valid_parameters_pass_validation(): void
    {
        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'pizza',
            'lat' => 40.7128,
            'lng' => -74.0060,
            'radius_km' => 5,
            'per_page' => 50,
        ]));

        // Should not return 422 validation error
        $this->assertNotEquals(422, $response->status());
    }

    /**
     * Test that missing required 'q' parameter returns 422.
     */
    public function test_missing_query_parameter_fails(): void
    {
        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'lat' => 40.7128,
            'lng' => -74.0060,
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['q']);
    }

    /**
     * Test that missing required 'lat' parameter returns 422.
     */
    public function test_missing_lat_parameter_fails(): void
    {
        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'test',
            'lng' => -74.0060,
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['lat']);
    }

    /**
     * Test that missing required 'lng' parameter returns 422.
     */
    public function test_missing_lng_parameter_fails(): void
    {
        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'test',
            'lat' => 40.7128,
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['lng']);
    }

    /**
     * Test that query string less than 2 characters fails.
     */
    public function test_query_too_short_fails(): void
    {
        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'a',
            'lat' => 40.7128,
            'lng' => -74.0060,
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['q']);
    }

    /**
     * Test that latitude out of range fails.
     */
    public function test_invalid_latitude_range_fails(): void
    {
        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'test',
            'lat' => 100,
            'lng' => -74.0060,
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['lat']);
    }

    /**
     * Test that longitude out of range fails.
     */
    public function test_invalid_longitude_range_fails(): void
    {
        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'test',
            'lat' => 40.7128,
            'lng' => 200,
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['lng']);
    }

    /**
     * Test that radius_km below minimum fails.
     */
    public function test_radius_below_minimum_fails(): void
    {
        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'test',
            'lat' => 40.7128,
            'lng' => -74.0060,
            'radius_km' => 0.05,
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['radius_km']);
    }

    /**
     * Test that radius_km above maximum fails.
     */
    public function test_radius_above_maximum_fails(): void
    {
        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'test',
            'lat' => 40.7128,
            'lng' => -74.0060,
            'radius_km' => 100,
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['radius_km']);
    }

    /**
     * Test that per_page exceeding maximum fails.
     */
    public function test_per_page_exceeding_maximum_fails(): void
    {
        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'test',
            'lat' => 40.7128,
            'lng' => -74.0060,
            'per_page' => 600,
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    /**
     * Test that negative min_wage_reports fails.
     */
    public function test_negative_min_wage_reports_fails(): void
    {
        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'test',
            'lat' => 40.7128,
            'lng' => -74.0060,
            'min_wage_reports' => -1,
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['min_wage_reports']);
    }

    /**
     * Test edge case: minimum valid values.
     */
    public function test_edge_case_minimum_valid_values(): void
    {
        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'ab',
            'lat' => -90,
            'lng' => -180,
            'radius_km' => 0.1,
            'per_page' => 1,
        ]));

        $this->assertNotEquals(422, $response->status());
    }

    /**
     * Test edge case: maximum valid values.
     */
    public function test_edge_case_maximum_valid_values(): void
    {
        $response = $this->getJson('/api/v1/locations/search?' . http_build_query([
            'q' => 'test',
            'lat' => 90,
            'lng' => 180,
            'radius_km' => 50,
            'per_page' => 500,
        ]));

        $this->assertNotEquals(422, $response->status());
    }
}
