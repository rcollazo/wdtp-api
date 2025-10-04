<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\OsmLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * OverpassService
 *
 * Query OpenStreetMap data via Overpass API for location search integration.
 * Builds Overpass QL queries for name-based and category-based searches,
 * executes HTTP requests with timeout handling, and returns OsmLocation DTOs.
 *
 * Use Http::fake() for testing - no real API calls in tests.
 */
class OverpassService
{
    /**
     * Category to OSM tag mappings for common search terms
     */
    private const CATEGORY_MAPPINGS = [
        'restaurant' => ['amenity' => 'restaurant'],
        'cafe' => ['amenity' => 'cafe'],
        'coffee' => ['amenity' => 'cafe'],
        'retail' => ['shop' => '*'],
        'store' => ['shop' => '*'],
        'shop' => ['shop' => '*'],
        'healthcare' => ['amenity' => 'hospital'],
        'hospital' => ['amenity' => 'hospital'],
        'clinic' => ['amenity' => 'clinic'],
        'pharmacy' => ['amenity' => 'pharmacy'],
    ];

    /**
     * Search for OpenStreetMap POIs by name or category
     *
     * @param string $query Search term (name or category)
     * @param float $lat Center latitude
     * @param float $lng Center longitude
     * @param float $radiusKm Search radius in kilometers
     * @return Collection<OsmLocation> Collection of OsmLocation DTOs
     * @throws \Exception On HTTP errors or timeouts
     */
    public function search(string $query, float $lat, float $lng, float $radiusKm): Collection
    {
        // Check if service is enabled
        if (! config('services.overpass.enabled')) {
            return collect();
        }

        // Build Overpass QL query
        $overpassQuery = $this->buildOverpassQuery($query, $lat, $lng, $radiusKm);

        // Execute HTTP request with timeout
        $response = Http::timeout(config('services.overpass.timeout'))
            ->asForm()
            ->post(config('services.overpass.base_url'), [
                'data' => $overpassQuery,
            ]);

        // Throw exception on HTTP errors (handled in controller)
        $response->throw();

        // Parse response and return OsmLocation DTOs
        return $this->parseResponse($response->json());
    }

    /**
     * Build Overpass QL query for name or category search
     *
     * @param string $query Search term
     * @param float $lat Center latitude
     * @param float $lng Center longitude
     * @param float $radiusKm Search radius in kilometers
     * @return string Overpass QL query string
     */
    private function buildOverpassQuery(string $query, float $lat, float $lng, float $radiusKm): string
    {
        $radiusMeters = (int) ($radiusKm * 1000);
        $category = $this->detectCategory($query);

        // Set timeout for Overpass server
        $qlQuery = "[timeout:10][out:json];\n";

        if ($category) {
            // Category-based search
            $tagFilter = $this->buildTagFilter($category);
            $qlQuery .= "(\n";
            $qlQuery .= "  node{$tagFilter}(around:{$radiusMeters},{$lat},{$lng});\n";
            $qlQuery .= "  way{$tagFilter}(around:{$radiusMeters},{$lat},{$lng});\n";
            $qlQuery .= ");\n";
        } else {
            // Name-based search with case-insensitive regex
            $escapedQuery = addslashes($query);
            $qlQuery .= "(\n";
            $qlQuery .= "  node[\"name\"~\"{$escapedQuery}\",i](around:{$radiusMeters},{$lat},{$lng});\n";
            $qlQuery .= "  way[\"name\"~\"{$escapedQuery}\",i](around:{$radiusMeters},{$lat},{$lng});\n";
            $qlQuery .= ");\n";
        }

        $qlQuery .= "out center;";

        return $qlQuery;
    }

    /**
     * Parse Overpass API JSON response into OsmLocation DTOs
     *
     * @param array<string, mixed> $json Response JSON
     * @return Collection<OsmLocation>
     */
    private function parseResponse(array $json): Collection
    {
        $elements = $json['elements'] ?? [];

        return collect($elements)
            ->filter(function ($element) {
                // Filter incomplete elements
                $hasCoordinates = isset($element['lat'], $element['lon']) ||
                    isset($element['center']['lat'], $element['center']['lon']);
                $hasName = isset($element['tags']['name']);

                return $hasCoordinates && $hasName;
            })
            ->map(function ($element) {
                // Extract coordinates (handle both node and way elements)
                $lat = $element['lat'] ?? $element['center']['lat'];
                $lon = $element['lon'] ?? $element['center']['lon'];

                // Build OSM ID in format 'node/123456' or 'way/789012'
                $osmId = $element['type'] . '/' . $element['id'];

                return new OsmLocation(
                    osm_id: $osmId,
                    osm_type: $element['type'],
                    name: $element['tags']['name'],
                    latitude: (float) $lat,
                    longitude: (float) $lon,
                    tags: $element['tags'] ?? [],
                );
            });
    }

    /**
     * Detect if query matches a known category
     *
     * @param string $query Search term
     * @return string|null Category name if matched, null otherwise
     */
    private function detectCategory(string $query): ?string
    {
        $normalized = strtolower(trim($query));

        return array_key_exists($normalized, self::CATEGORY_MAPPINGS) ? $normalized : null;
    }

    /**
     * Build OSM tag filter for category search
     *
     * @param string $category Category name
     * @return string Tag filter string for Overpass QL
     */
    private function buildTagFilter(string $category): string
    {
        $mapping = self::CATEGORY_MAPPINGS[$category];
        $filters = [];

        foreach ($mapping as $key => $value) {
            if ($value === '*') {
                $filters[] = "[{$key}]";
            } else {
                $filters[] = "[{$key}={$value}]";
            }
        }

        return implode('', $filters);
    }
}
