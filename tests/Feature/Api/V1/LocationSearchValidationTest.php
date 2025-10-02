<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationSearchValidationTest extends TestCase
{
    use RefreshDatabase;

    private string $endpoint = '/api/v1/locations/search';

    /** @test */
    public function it_returns_422_when_query_parameter_is_missing(): void
    {
        $response = $this->getJson($this->endpoint.'?lat=40.7128&lng=-74.0060');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['q']);
        $response->assertJson([
            'message' => 'Search query is required',
        ]);
    }

    /** @test */
    public function it_returns_422_when_latitude_is_missing(): void
    {
        $response = $this->getJson($this->endpoint.'?q=McDonald&lng=-74.0060');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lat']);
        $response->assertJson([
            'message' => 'Latitude is required',
        ]);
    }

    /** @test */
    public function it_returns_422_when_longitude_is_missing(): void
    {
        $response = $this->getJson($this->endpoint.'?q=McDonald&lat=40.7128');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lng']);
        $response->assertJson([
            'message' => 'Longitude is required',
        ]);
    }

    /** @test */
    public function it_returns_422_when_latitude_is_out_of_range_too_low(): void
    {
        $response = $this->getJson($this->endpoint.'?q=McDonald&lat=-91&lng=-74.0060');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lat']);
        $response->assertJsonFragment([
            'Latitude must be between -90 and 90',
        ]);
    }

    /** @test */
    public function it_returns_422_when_latitude_is_out_of_range_too_high(): void
    {
        $response = $this->getJson($this->endpoint.'?q=McDonald&lat=91&lng=-74.0060');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lat']);
        $response->assertJsonFragment([
            'Latitude must be between -90 and 90',
        ]);
    }

    /** @test */
    public function it_returns_422_when_longitude_is_out_of_range_too_low(): void
    {
        $response = $this->getJson($this->endpoint.'?q=McDonald&lat=40.7128&lng=-181');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lng']);
        $response->assertJsonFragment([
            'Longitude must be between -180 and 180',
        ]);
    }

    /** @test */
    public function it_returns_422_when_longitude_is_out_of_range_too_high(): void
    {
        $response = $this->getJson($this->endpoint.'?q=McDonald&lat=40.7128&lng=181');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lng']);
        $response->assertJsonFragment([
            'Longitude must be between -180 and 180',
        ]);
    }

    /** @test */
    public function it_returns_422_when_radius_km_is_too_small(): void
    {
        $response = $this->getJson($this->endpoint.'?q=McDonald&lat=40.7128&lng=-74.0060&radius_km=0.05');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['radius_km']);
        $response->assertJsonFragment([
            'The radius must be at least 0.1 kilometers',
        ]);
    }

    /** @test */
    public function it_returns_422_when_radius_km_is_too_large(): void
    {
        $response = $this->getJson($this->endpoint.'?q=McDonald&lat=40.7128&lng=-74.0060&radius_km=51');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['radius_km']);
        $response->assertJsonFragment([
            'The radius cannot exceed 50 kilometers',
        ]);
    }

    /** @test */
    public function it_returns_422_when_per_page_is_too_large(): void
    {
        $response = $this->getJson($this->endpoint.'?q=McDonald&lat=40.7128&lng=-74.0060&per_page=501');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['per_page']);
        $response->assertJsonFragment([
            'Cannot request more than 500 results per page',
        ]);
    }

    /** @test */
    public function it_returns_422_when_per_page_is_too_small(): void
    {
        $response = $this->getJson($this->endpoint.'?q=McDonald&lat=40.7128&lng=-74.0060&per_page=0');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['per_page']);
        $response->assertJsonFragment([
            'Results per page must be at least 1',
        ]);
    }

    /** @test */
    public function it_returns_422_when_query_is_too_short(): void
    {
        $response = $this->getJson($this->endpoint.'?q=M&lat=40.7128&lng=-74.0060');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['q']);
        $response->assertJsonFragment([
            'Search query must be at least 2 characters',
        ]);
    }

    /** @test */
    public function it_returns_200_with_valid_parameters(): void
    {
        $response = $this->getJson($this->endpoint.'?q=McDonald&lat=40.7128&lng=-74.0060');

        // Should not return validation errors
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'meta',
        ]);
    }

    /** @test */
    public function it_validates_multiple_errors_in_single_request(): void
    {
        // Missing q, invalid lat, invalid lng
        $response = $this->getJson($this->endpoint.'?lat=100&lng=200');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['q', 'lat', 'lng']);
    }

    /** @test */
    public function it_validates_edge_case_latitude_minimum(): void
    {
        $response = $this->getJson($this->endpoint.'?q=McDonald&lat=-90&lng=-74.0060');

        $response->assertStatus(200); // -90 is valid (exact boundary)
    }

    /** @test */
    public function it_validates_edge_case_latitude_maximum(): void
    {
        $response = $this->getJson($this->endpoint.'?q=McDonald&lat=90&lng=-74.0060');

        $response->assertStatus(200); // 90 is valid (exact boundary)
    }

    /** @test */
    public function it_validates_edge_case_longitude_minimum(): void
    {
        $response = $this->getJson($this->endpoint.'?q=McDonald&lat=40.7128&lng=-180');

        $response->assertStatus(200); // -180 is valid (exact boundary)
    }

    /** @test */
    public function it_validates_edge_case_longitude_maximum(): void
    {
        $response = $this->getJson($this->endpoint.'?q=McDonald&lat=40.7128&lng=180');

        $response->assertStatus(200); // 180 is valid (exact boundary)
    }

    /** @test */
    public function it_validates_edge_case_radius_minimum(): void
    {
        $response = $this->getJson($this->endpoint.'?q=McDonald&lat=40.7128&lng=-74.0060&radius_km=0.1');

        $response->assertStatus(200); // 0.1 is valid (exact minimum)
    }

    /** @test */
    public function it_validates_edge_case_radius_maximum(): void
    {
        $response = $this->getJson($this->endpoint.'?q=McDonald&lat=40.7128&lng=-74.0060&radius_km=50');

        $response->assertStatus(200); // 50 is valid (exact maximum)
    }
}
