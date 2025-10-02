<?php

namespace Tests\Unit\Services;

use App\Services\RelevanceScorer;
use stdClass;
use Tests\TestCase;

class RelevanceScorerTest extends TestCase
{
    private RelevanceScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new RelevanceScorer;
    }

    /** @test */
    public function it_calculates_perfect_text_match_at_center_location(): void
    {
        // Perfect text match (text_rank = 1.0) at exact center (distance = 0)
        // Expected: (1.0 × 0.6) + (1.0 × 0.4) = 1.0
        $location = $this->createLocationObject(1.0, 0);

        $score = $this->scorer->calculate($location, 10.0);

        $this->assertEquals(1.0, $score);
    }

    /** @test */
    public function it_calculates_perfect_text_match_at_edge_of_radius(): void
    {
        // Perfect text match (text_rank = 1.0) at edge of 10km radius (distance = 10000m)
        // Proximity score: 1 - (10000 / 10000) = 0.0
        // Expected: (1.0 × 0.6) + (0.0 × 0.4) = 0.6
        $location = $this->createLocationObject(1.0, 10000);

        $score = $this->scorer->calculate($location, 10.0);

        $this->assertEquals(0.6, $score);
    }

    /** @test */
    public function it_calculates_moderate_text_match_at_center_location(): void
    {
        // Moderate text match (text_rank = 0.7) at exact center (distance = 0)
        // Expected: (0.7 × 0.6) + (1.0 × 0.4) = 0.42 + 0.4 = 0.82
        $location = $this->createLocationObject(0.7, 0);

        $score = $this->scorer->calculate($location, 10.0);

        $this->assertEquals(0.82, $score);
    }

    /** @test */
    public function it_handles_zero_distance_edge_case(): void
    {
        // Zero distance should give maximum proximity score (1.0)
        $location = $this->createLocationObject(0.5, 0);

        $score = $this->scorer->calculate($location, 10.0);

        // (0.5 × 0.6) + (1.0 × 0.4) = 0.3 + 0.4 = 0.7
        $this->assertEquals(0.7, $score);
    }

    /** @test */
    public function it_handles_max_distance_edge_case(): void
    {
        // At max radius, proximity score should be 0.0
        $maxRadiusKm = 50.0;
        $maxDistanceMeters = $maxRadiusKm * 1000;
        $location = $this->createLocationObject(0.8, $maxDistanceMeters);

        $score = $this->scorer->calculate($location, $maxRadiusKm);

        // (0.8 × 0.6) + (0.0 × 0.4) = 0.48
        $this->assertEquals(0.48, $score);
    }

    /** @test */
    public function it_caps_text_rank_above_one(): void
    {
        // PostgreSQL ts_rank() can sometimes exceed 1.0
        // Service should cap it at 1.0
        $location = $this->createLocationObject(1.5, 0);

        $score = $this->scorer->calculate($location, 10.0);

        // Should be capped: (1.0 × 0.6) + (1.0 × 0.4) = 1.0
        $this->assertEquals(1.0, $score);
    }

    /** @test */
    public function it_calculates_proximity_score_accurately(): void
    {
        // Test proximity score calculation at various distances
        // Distance = 5km out of 10km max radius
        // Proximity score: 1 - (5000 / 10000) = 0.5
        $location = $this->createLocationObject(0.8, 5000);

        $score = $this->scorer->calculate($location, 10.0);

        // (0.8 × 0.6) + (0.5 × 0.4) = 0.48 + 0.2 = 0.68
        $this->assertEquals(0.68, $score);
    }

    /** @test */
    public function it_verifies_weight_distribution(): void
    {
        // Verify 60/40 weight split
        // Text component: 1.0 × 0.6 = 0.6
        // Proximity component: 1.0 × 0.4 = 0.4
        $location = $this->createLocationObject(1.0, 0);

        $score = $this->scorer->calculate($location, 10.0);

        $this->assertEquals(1.0, $score); // 0.6 + 0.4 = 1.0
    }

    /** @test */
    public function it_rounds_to_two_decimal_places(): void
    {
        // Test rounding behavior
        // (0.777 × 0.6) + (0.333 × 0.4) = 0.4662 + 0.1332 = 0.5994
        // Should round to 0.6
        $location = $this->createLocationObject(0.777, 6670);

        $score = $this->scorer->calculate($location, 10.0);

        $this->assertEquals(0.6, $score);
    }

    /** @test */
    public function it_handles_location_with_default_text_rank(): void
    {
        // If text_rank is not set (null), should default to 0.5
        $location = new stdClass;
        $location->distance_meters = 0;
        // text_rank intentionally not set

        $score = $this->scorer->calculate($location, 10.0);

        // (0.5 × 0.6) + (1.0 × 0.4) = 0.3 + 0.4 = 0.7
        $this->assertEquals(0.7, $score);
    }

    /** @test */
    public function it_handles_location_with_default_distance(): void
    {
        // If distance_meters is not set (null), should default to 0
        $location = new stdClass;
        $location->text_rank = 0.8;
        // distance_meters intentionally not set

        $score = $this->scorer->calculate($location, 10.0);

        // (0.8 × 0.6) + (1.0 × 0.4) = 0.48 + 0.4 = 0.88
        $this->assertEquals(0.88, $score);
    }

    /** @test */
    public function it_handles_distance_beyond_max_radius(): void
    {
        // Distance beyond max radius should result in negative proximity,
        // but should be clamped to 0
        $location = $this->createLocationObject(0.8, 15000); // 15km > 10km

        $score = $this->scorer->calculate($location, 10.0);

        // Proximity: 1 - (15000 / 10000) = -0.5, clamped to 0
        // (0.8 × 0.6) + (0.0 × 0.4) = 0.48
        $this->assertEquals(0.48, $score);
    }

    /** @test */
    public function it_calculates_realistic_mid_range_scenario(): void
    {
        // Realistic scenario: good text match at moderate distance
        // Text rank: 0.85, Distance: 2.5km out of 10km
        // Proximity: 1 - (2500 / 10000) = 0.75
        $location = $this->createLocationObject(0.85, 2500);

        $score = $this->scorer->calculate($location, 10.0);

        // (0.85 × 0.6) + (0.75 × 0.4) = 0.51 + 0.3 = 0.81
        $this->assertEquals(0.81, $score);
    }

    /** @test */
    public function it_handles_small_search_radius(): void
    {
        // Test with smaller radius (1km)
        // Distance: 500m out of 1000m
        // Proximity: 1 - (500 / 1000) = 0.5
        $location = $this->createLocationObject(0.9, 500);

        $score = $this->scorer->calculate($location, 1.0);

        // (0.9 × 0.6) + (0.5 × 0.4) = 0.54 + 0.2 = 0.74
        $this->assertEquals(0.74, $score);
    }

    /** @test */
    public function it_handles_large_search_radius(): void
    {
        // Test with larger radius (50km)
        // Distance: 25km out of 50km
        // Proximity: 1 - (25000 / 50000) = 0.5
        $location = $this->createLocationObject(0.6, 25000);

        $score = $this->scorer->calculate($location, 50.0);

        // (0.6 × 0.6) + (0.5 × 0.4) = 0.36 + 0.2 = 0.56
        $this->assertEquals(0.56, $score);
    }

    /**
     * Helper method to create a mock location object.
     */
    private function createLocationObject(float $textRank, float $distanceMeters): object
    {
        $location = new stdClass;
        $location->text_rank = $textRank;
        $location->distance_meters = $distanceMeters;

        return $location;
    }
}
