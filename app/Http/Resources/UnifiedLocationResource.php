<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Unified location resource for both WDTP locations and OSM POIs.
 *
 * Provides a consistent API response format regardless of the location source.
 * Currently handles WDTP Location models; OSM support will be added in a later task.
 */
class UnifiedLocationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // For now, only handle WDTP Location models (OSM support in Task 4.1)
        return [
            // Source identification
            'source' => 'wdtp',
            'location_id' => $this->id,
            'osm_id' => null,
            'osm_type' => null,

            // Basic location data
            'name' => $this->name,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,

            // Wage data information
            'has_wage_data' => $this->relationLoaded('wageReports') && $this->wageReports->count() > 0,
            'wage_reports_count' => $this->when(
                $this->relationLoaded('wageReports'),
                $this->wageReports->count() ?? 0
            ),

            // Address (using existing full_address accessor)
            'address' => $this->full_address,

            // Relationships
            'organization' => new OrganizationResource($this->whenLoaded('organization')),

            // Spatial query results (conditional)
            'distance_meters' => $this->when(
                isset($this->distance_meters),
                round($this->distance_meters)
            ),

            // Relevance scoring (conditional)
            'relevance_score' => $this->when(
                isset($this->relevance_score),
                round($this->relevance_score, 2)
            ),
        ];
    }
}
