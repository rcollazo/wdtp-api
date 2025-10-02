<?php

namespace Tests\Unit\Services;

use App\DataTransferObjects\OsmLocation;
use App\Services\OverpassService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OverpassServiceTest extends TestCase
{
    private OverpassService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new OverpassService;

        // Mock Overpass API configuration
        Config::set('services.overpass.enabled', true);
        Config::set('services.overpass.base_url', 'https://overpass-api.de/api/interpreter');
        Config::set('services.overpass.timeout', 10);
    }

    /** @test */
    public function it_returns_parsed_collection_on_successful_query(): void
    {
        Http::fake([
            '*' => Http::response($this->getValidOsmResponse(), 200),
        ]);

        $results = $this->service->search('McDonald\'s', 40.7128, -74.0060, 5.0);

        $this->assertCount(2, $results);
        $this->assertInstanceOf(OsmLocation::class, $results->first());
    }

    /** @test */
    public function it_throws_exception_on_timeout(): void
    {
        Http::fake([
            '*' => function () {
                throw new ConnectionException('Connection timeout');
            },
        ]);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Connection timeout');

        $this->service->search('McDonald\'s', 40.7128, -74.0060, 5.0);
    }

    /** @test */
    public function it_throws_exception_on_503_response(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Service unavailable'], 503),
        ]);

        $this->expectException(RequestException::class);

        $this->service->search('McDonald\'s', 40.7128, -74.0060, 5.0);
    }

    /** @test */
    public function it_throws_exception_on_429_rate_limit(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Rate limit exceeded'], 429),
        ]);

        $this->expectException(RequestException::class);

        $this->service->search('McDonald\'s', 40.7128, -74.0060, 5.0);
    }

    /** @test */
    public function it_throws_exception_on_malformed_response(): void
    {
        Http::fake([
            '*' => Http::response('Invalid JSON', 200),
        ]);

        $this->expectException(\JsonException::class);

        $this->service->search('McDonald\'s', 40.7128, -74.0060, 5.0);
    }

    /** @test */
    public function it_generates_correct_query_for_name_search(): void
    {
        Http::fake([
            '*' => Http::response($this->getValidOsmResponse(), 200),
        ]);

        $this->service->search('Starbucks', 40.7128, -74.0060, 5.0);

        Http::assertSent(function ($request) {
            $query = $request['data'];

            // Verify timeout setting
            $this->assertStringContainsString('[timeout:10]', $query);

            // Verify output format
            $this->assertStringContainsString('[out:json]', $query);

            // Verify name-based search with regex
            $this->assertStringContainsString('node["name"~"Starbucks",i]', $query);
            $this->assertStringContainsString('way["name"~"Starbucks",i]', $query);

            // Verify around radius filter
            $this->assertStringContainsString('around:5000,40.7128,-74.006', $query);

            // Verify output center directive
            $this->assertStringContainsString('out center', $query);

            return true;
        });
    }

    /** @test */
    public function it_generates_correct_query_for_category_search(): void
    {
        Http::fake([
            '*' => Http::response($this->getValidOsmResponse(), 200),
        ]);

        $this->service->search('restaurant', 40.7128, -74.0060, 10.0);

        Http::assertSent(function ($request) {
            $query = $request['data'];

            // Verify category tag filter instead of name search
            $this->assertStringContainsString('node[amenity=restaurant]', $query);
            $this->assertStringContainsString('way[amenity=restaurant]', $query);

            // Verify around radius filter (10km = 10000m)
            $this->assertStringContainsString('around:10000,40.7128,-74.006', $query);

            return true;
        });
    }

    /** @test */
    public function it_parses_response_with_valid_osm_json(): void
    {
        Http::fake([
            '*' => Http::response($this->getValidOsmResponse(), 200),
        ]);

        $results = $this->service->search('McDonald\'s', 40.7128, -74.0060, 5.0);

        // Verify collection structure
        $this->assertCount(2, $results);

        // Verify first result structure
        $first = $results->first();
        $this->assertEquals('node/123456', $first->osm_id);
        $this->assertEquals('node', $first->osm_type);
        $this->assertEquals('McDonald\'s Times Square', $first->name);
        $this->assertEquals(40.7580, $first->latitude);
        $this->assertEquals(-73.9855, $first->longitude);
        $this->assertIsArray($first->tags);
        $this->assertEquals('restaurant', $first->tags['amenity']);
    }

    /** @test */
    public function it_filters_incomplete_elements_during_parsing(): void
    {
        Http::fake([
            '*' => Http::response($this->getIncompleteOsmResponse(), 200),
        ]);

        $results = $this->service->search('McDonald\'s', 40.7128, -74.0060, 5.0);

        // Should only return 1 valid element (second one is incomplete)
        $this->assertCount(1, $results);
        $this->assertEquals('McDonald\'s Times Square', $results->first()->name);
    }

    /** @test */
    public function it_returns_empty_collection_when_service_disabled(): void
    {
        Config::set('services.overpass.enabled', false);

        // No HTTP fake needed - service should return early
        $results = $this->service->search('McDonald\'s', 40.7128, -74.0060, 5.0);

        $this->assertCount(0, $results);
        Http::assertNothingSent();
    }

    /** @test */
    public function it_handles_category_mapping_for_restaurants(): void
    {
        Http::fake([
            '*' => Http::response($this->getValidOsmResponse(), 200),
        ]);

        $this->service->search('restaurant', 40.7128, -74.0060, 5.0);

        Http::assertSent(function ($request) {
            return str_contains($request['data'], '[amenity=restaurant]');
        });
    }

    /** @test */
    public function it_handles_category_mapping_for_cafes(): void
    {
        Http::fake([
            '*' => Http::response($this->getValidOsmResponse(), 200),
        ]);

        $this->service->search('cafe', 40.7128, -74.0060, 5.0);

        Http::assertSent(function ($request) {
            return str_contains($request['data'], '[amenity=cafe]');
        });
    }

    /** @test */
    public function it_handles_category_mapping_for_retail(): void
    {
        Http::fake([
            '*' => Http::response($this->getValidOsmResponse(), 200),
        ]);

        $this->service->search('retail', 40.7128, -74.0060, 5.0);

        Http::assertSent(function ($request) {
            // Retail maps to [shop] (wildcard)
            return str_contains($request['data'], 'node[shop]') &&
                str_contains($request['data'], 'way[shop]');
        });
    }

    /** @test */
    public function it_handles_category_mapping_for_healthcare(): void
    {
        Http::fake([
            '*' => Http::response($this->getValidOsmResponse(), 200),
        ]);

        $this->service->search('healthcare', 40.7128, -74.0060, 5.0);

        Http::assertSent(function ($request) {
            return str_contains($request['data'], '[amenity=hospital]');
        });
    }

    /** @test */
    public function it_includes_around_radius_filter_in_query(): void
    {
        Http::fake([
            '*' => Http::response($this->getValidOsmResponse(), 200),
        ]);

        // Test with 25km radius
        $this->service->search('McDonald\'s', 40.7128, -74.0060, 25.0);

        Http::assertSent(function ($request) {
            // 25km = 25000m
            return str_contains($request['data'], 'around:25000,40.7128,-74.006');
        });
    }

    /**
     * Helper: Get valid OSM API response
     */
    private function getValidOsmResponse(): array
    {
        return [
            'elements' => [
                [
                    'type' => 'node',
                    'id' => 123456,
                    'lat' => 40.7580,
                    'lon' => -73.9855,
                    'tags' => [
                        'name' => 'McDonald\'s Times Square',
                        'amenity' => 'restaurant',
                        'cuisine' => 'burger',
                    ],
                ],
                [
                    'type' => 'way',
                    'id' => 789012,
                    'center' => [
                        'lat' => 40.7489,
                        'lon' => -73.9680,
                    ],
                    'tags' => [
                        'name' => 'McDonald\'s Grand Central',
                        'amenity' => 'restaurant',
                    ],
                ],
            ],
        ];
    }

    /**
     * Helper: Get OSM response with incomplete elements
     */
    private function getIncompleteOsmResponse(): array
    {
        return [
            'elements' => [
                [
                    'type' => 'node',
                    'id' => 123456,
                    'lat' => 40.7580,
                    'lon' => -73.9855,
                    'tags' => [
                        'name' => 'McDonald\'s Times Square',
                        'amenity' => 'restaurant',
                    ],
                ],
                [
                    // Missing coordinates - should be filtered out
                    'type' => 'node',
                    'id' => 234567,
                    'tags' => [
                        'name' => 'Incomplete Location',
                        'amenity' => 'restaurant',
                    ],
                ],
                [
                    // Missing name - should be filtered out
                    'type' => 'node',
                    'id' => 345678,
                    'lat' => 40.7489,
                    'lon' => -73.9680,
                    'tags' => [
                        'amenity' => 'restaurant',
                    ],
                ],
            ],
        ];
    }
}
