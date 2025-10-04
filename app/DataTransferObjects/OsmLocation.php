<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * OsmLocation Data Transfer Object
 *
 * Represents OpenStreetMap POI data in a structured format compatible with
 * UnifiedLocationResource. This DTO is used by OverpassService to return
 * OSM results in a consistent structure that can be merged with WDTP locations.
 *
 * Properties use readonly promotion for immutability. The text_rank defaults
 * to 0.5 representing moderate relevance for OSM POIs without PostgreSQL
 * full-text search scores.
 *
 * OSM tags follow the addr:* namespace convention for address components.
 */
readonly class OsmLocation
{
    /**
     * Create a new OsmLocation instance.
     *
     * @param string $osm_id OSM element ID in format 'node/123456' or 'way/789012'
     * @param string $osm_type OSM element type ('node' or 'way')
     * @param string $name POI name from OSM tags
     * @param float $latitude Latitude coordinate
     * @param float $longitude Longitude coordinate
     * @param array<string, mixed> $tags Full OSM tags array for address formatting
     * @param float|null $distance_meters Calculated distance from search center
     * @param float|null $relevance_score Calculated relevance score (0.0-1.0)
     * @param float $text_rank Text search relevance (default 0.5 for OSM results)
     */
    public function __construct(
        public string $osm_id,
        public string $osm_type,
        public string $name,
        public float $latitude,
        public float $longitude,
        public array $tags,
        public ?float $distance_meters = null,
        public ?float $relevance_score = null,
        public float $text_rank = 0.5,
    ) {
    }

    /**
     * Return a new instance with the provided distance in meters.
     *
     * DTO promotes read-only state; cloning ensures immutable semantics while
     * still letting callers derive enriched variants for scoring and sorting.
     */
    public function withDistance(float $distanceMeters): self
    {
        return new self(
            osm_id: $this->osm_id,
            osm_type: $this->osm_type,
            name: $this->name,
            latitude: $this->latitude,
            longitude: $this->longitude,
            tags: $this->tags,
            distance_meters: $distanceMeters,
            relevance_score: $this->relevance_score,
            text_rank: $this->text_rank,
        );
    }

    /**
     * Return a new instance with the provided relevance score.
     *
     * Maintains immutability while allowing downstream layers to attach computed
     * relevance without mutating the original Overpass payload.
     */
    public function withRelevance(float $relevanceScore): self
    {
        return new self(
            osm_id: $this->osm_id,
            osm_type: $this->osm_type,
            name: $this->name,
            latitude: $this->latitude,
            longitude: $this->longitude,
            tags: $this->tags,
            distance_meters: $this->distance_meters,
            relevance_score: $relevanceScore,
            text_rank: $this->text_rank,
        );
    }

    /**
     * Format address from OSM tags.
     *
     * Extracts address components from OSM tags following the addr:* namespace
     * convention and joins them into a formatted address string.
     *
     * @return string|null Formatted address or null if no components available
     */
    public function formatAddress(): ?string
    {
        $components = array_filter([
            $this->tags['addr:housenumber'] ?? '',
            $this->tags['addr:street'] ?? '',
            $this->tags['addr:city'] ?? '',
            $this->tags['addr:state'] ?? '',
        ]);

        if (empty($components)) {
            return null;
        }

        return implode(', ', $components);
    }
}
