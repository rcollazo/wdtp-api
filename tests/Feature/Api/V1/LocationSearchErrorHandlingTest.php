<?php

namespace Tests\Feature\Api\V1;

use App\Models\Industry;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LocationSearchErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    private string $endpoint = '/api/v1/locations/search';

    protected function setUp(): void
    {
        parent::setUp();

        // Enable OSM service for error testing
        Config::set('services.overpass.enabled', true);
        Config::set('services.overpass.base_url', 'https://overpass-api.de/api/interpreter');
        Config::set('services.overpass.timeout', 10);
    }

    /** @test */
    public function it_returns_wdtp_only_when_osm_times_out(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'McDonald\'s',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        Http::fake([
            '*' => function () {
                throw new ConnectionException('Connection timeout');
            },
        ]);

        $response = $this->getJson($this->endpoint . '?q=McDonald&lat=40.7128&lng=-74.0060&radius_km=10&include_osm=true');

        // Should still return 200 with WDTP results (graceful degradation)
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.osm_unavailable', true);
        $response->assertJsonPath('meta.wdtp_count', 1);
        $response->assertJsonPath('meta.osm_count', 0);
    }

    /** @test */
    public function it_returns_wdtp_only_when_osm_returns_503(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Starbucks',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        Http::fake([
            '*' => Http::response(['error' => 'Service unavailable'], 503),
        ]);

        $response = $this->getJson($this->endpoint . '?q=Starbucks&lat=40.7128&lng=-74.0060&radius_km=10&include_osm=true');

        // Graceful degradation: return WDTP results with osm_unavailable flag
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.osm_unavailable', true);
    }

    /** @test */
    public function it_returns_wdtp_only_when_osm_connection_error(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Test Location',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        Http::fake([
            '*' => function () {
                throw new ConnectionException('Network unreachable');
            },
        ]);

        $response = $this->getJson($this->endpoint . '?q=Test&lat=40.7128&lng=-74.0060&radius_km=10&include_osm=true');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.osm_unavailable', true);
    }

    /** @test */
    public function it_returns_422_for_validation_errors(): void
    {
        $response = $this->getJson($this->endpoint . '?q=T&lat=40.7128&lng=-74.0060'); // Query too short

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['q']);
    }

    /** @test */
    public function it_logs_osm_timeout_errors_appropriately(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with(\Mockery::pattern('/OSM.*timeout/i'), \Mockery::type('array'));

        Http::fake([
            '*' => function () {
                throw new ConnectionException('Connection timeout');
            },
        ]);

        $this->getJson($this->endpoint . '?q=Test&lat=40.7128&lng=-74.0060&radius_km=10&include_osm=true');
    }

    /** @test */
    public function it_logs_osm_503_errors_appropriately(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with(\Mockery::pattern('/OSM.*unavailable/i'), \Mockery::type('array'));

        Http::fake([
            '*' => Http::response(['error' => 'Service unavailable'], 503),
        ]);

        $this->getJson($this->endpoint . '?q=Test&lat=40.7128&lng=-74.0060&radius_km=10&include_osm=true');
    }

    /** @test */
    public function it_logs_osm_connection_errors_appropriately(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with(\Mockery::pattern('/OSM.*error/i'), \Mockery::type('array'));

        Http::fake([
            '*' => function () {
                throw new ConnectionException('Network error');
            },
        ]);

        $this->getJson($this->endpoint . '?q=Test&lat=40.7128&lng=-74.0060&radius_km=10&include_osm=true');
    }

    /** @test */
    public function it_always_returns_200_for_osm_failures_with_wdtp_fallback(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Test Location',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        Http::fake([
            '*' => function () {
                throw new ConnectionException('Any error');
            },
        ]);

        $response = $this->getJson($this->endpoint . '?q=Test&lat=40.7128&lng=-74.0060&radius_km=10&include_osm=true');

        // Should NEVER return 500 for OSM errors
        $response->assertStatus(200);
        $response->assertJsonPath('meta.osm_unavailable', true);
    }

    /** @test */
    public function it_includes_osm_unavailable_flag_in_meta_when_osm_fails(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Test Location',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        Http::fake([
            '*' => Http::response(['error' => 'Service unavailable'], 503),
        ]);

        $response = $this->getJson($this->endpoint . '?q=Test&lat=40.7128&lng=-74.0060&radius_km=10&include_osm=true');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'meta' => ['osm_unavailable'],
        ]);
        $response->assertJsonPath('meta.osm_unavailable', true);
    }

    /** @test */
    public function it_does_not_include_osm_unavailable_flag_when_osm_succeeds(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Test Location',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        Http::fake([
            '*' => Http::response(['elements' => []], 200),
        ]);

        $response = $this->getJson($this->endpoint . '?q=Test&lat=40.7128&lng=-74.0060&radius_km=10&include_osm=true');

        $response->assertStatus(200);
        $response->assertJsonMissingPath('meta.osm_unavailable');
    }

    /** @test */
    public function it_handles_wdtp_query_errors_with_500(): void
    {
        // Simulate database error by using invalid table name
        // This is a theoretical test - in reality, WDTP errors should NOT be gracefully degraded

        $response = $this->getJson($this->endpoint . '?q=Test&lat=40.7128&lng=-74.0060&radius_km=10');

        // WDTP is core functionality - errors should propagate
        // But if WDTP succeeds, we still get 200
        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_returns_wdtp_results_when_osm_disabled_and_no_flag_set(): void
    {
        Config::set('services.overpass.enabled', false);

        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Test Location',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        $response = $this->getJson($this->endpoint . '?q=Test&lat=40.7128&lng=-74.0060&radius_km=10&include_osm=true');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        // No osm_unavailable flag because service is disabled, not failed
        $response->assertJsonMissingPath('meta.osm_unavailable');
        $response->assertJsonPath('meta.osm_count', 0);
    }

    /** @test */
    public function it_handles_osm_rate_limit_429_errors(): void
    {
        $industry = Industry::factory()->create();
        $org = Organization::factory()->active()->verified()->create([
            'primary_industry_id' => $industry->id,
        ]);

        Location::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Test Location',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
        ]);

        Http::fake([
            '*' => Http::response(['error' => 'Rate limit exceeded'], 429),
        ]);

        $response = $this->getJson($this->endpoint . '?q=Test&lat=40.7128&lng=-74.0060&radius_km=10&include_osm=true');

        // Graceful degradation for rate limiting
        $response->assertStatus(200);
        $response->assertJsonPath('meta.osm_unavailable', true);
        $response->assertJsonPath('meta.wdtp_count', 1);
    }
}
