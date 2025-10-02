<?php

namespace App\Services;

/**
 * RelevanceScorer Service
 *
 * Calculates relevance scores for location search results by combining
 * text match quality (60%) and spatial proximity (40%).
 *
 * Scoring Algorithm:
 * relevance_score = (text_rank × 0.6) + (proximity_score × 0.4)
 *
 * Where:
 * - text_rank: PostgreSQL ts_rank() normalized to 0-1 scale (capped at 1.0)
 * - proximity_score: 1 - (distance_meters / max_radius_meters)
 *
 * This weighted approach prioritizes text relevance over distance,
 * ensuring that well-matching results rank higher even if slightly
 * farther away, while still considering proximity as a secondary factor.
 */
class RelevanceScorer
{
    // Scoring weights
    private const TEXT_WEIGHT = 0.6;

    private const PROXIMITY_WEIGHT = 0.4;

    // Text rank normalization cap
    private const TEXT_RANK_CAP = 1.0;

    /**
     * Calculate relevance score for a location.
     *
     * Works with both Location models and OsmLocation DTOs using duck typing.
     * Both types must provide text_rank and distance_meters properties.
     *
     * @param  object  $location  Location model or OsmLocation DTO
     * @param  float  $maxRadiusKm  Maximum search radius in kilometers
     * @return float Relevance score (0.0 to 1.0), rounded to 2 decimal places
     */
    public function calculate(object $location, float $maxRadiusKm): float
    {
        // Extract text rank (default 0.5 if not set)
        $textRank = $location->text_rank ?? 0.5;

        // Extract distance in meters (default 0 if not set)
        $distanceMeters = $location->distance_meters ?? 0;

        // Normalize text rank (cap at 1.0 to handle PostgreSQL ts_rank edge cases)
        $normalizedTextRank = min($textRank, self::TEXT_RANK_CAP);

        // Calculate proximity score: 1 - (distance / max_distance)
        $maxDistanceMeters = $maxRadiusKm * 1000;
        $proximityScore = 1 - ($distanceMeters / $maxDistanceMeters);

        // Clamp proximity score between 0 and 1
        $proximityScore = max(0, min(1, $proximityScore));

        // Apply weighted formula
        $relevanceScore = ($normalizedTextRank * self::TEXT_WEIGHT) + ($proximityScore * self::PROXIMITY_WEIGHT);

        // Round to 2 decimal places
        return round($relevanceScore, 2);
    }
}
