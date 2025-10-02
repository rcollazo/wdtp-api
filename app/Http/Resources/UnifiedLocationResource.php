<?php

namespace App\Http\Resources;

use App\DataTransferObjects\OsmLocation;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Unified location resource for both WDTP locations and OSM POIs.
 *
 * Provides a consistent API response format regardless of the location source.
 * Handles both Location models (WDTP) and OsmLocation DTOs (OSM) using instanceof checks.
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
        // Detect resource type using instanceof
        $isWdtpLocation = $this->resource instanceof Location;
        $isOsmLocation = $this->resource instanceof OsmLocation;

        return [
            // Source identification
            'source' => $isWdtpLocation ? 'wdtp' : 'osm',
            'location_id' => $isWdtpLocation ? $this->id : null,
            'osm_id' => $isOsmLocation ? $this->osm_id : null,
            'osm_type' => $isOsmLocation ? $this->osm_type : null,

            // Basic location data
            'name' => $this->name,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,

            // Wage data information
            'has_wage_data' => $isWdtpLocation
                ? ($this->relationLoaded('wageReports') && $this->wageReports->count() > 0)
                : false,
            'wage_reports_count' => $isWdtpLocation
                ? ($this->relationLoaded('wageReports') ? $this->wageReports->count() : 0)
                : 0,

            // Address (full_address for WDTP, formatAddress() for OSM)
            'address' => $isWdtpLocation
                ? $this->full_address
                : ($this->formatAddress() ?? ''),

            // Relationships (WDTP only)
            'organization' => $isWdtpLocation
                ? new OrganizationResource($this->whenLoaded('organization'))
                : null,

            // Spatial query results (conditional, both types)
            'distance_meters' => $this->when(
                isset($this->distance_meters),
                round($this->distance_meters)
            ),

            // Relevance scoring (conditional, both types)
            'relevance_score' => $this->when(
                isset($this->relevance_score),
                round($this->relevance_score, 2)
            ),
        ];
    }
}
