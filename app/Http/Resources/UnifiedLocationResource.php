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
 *
 * @OA\Schema(
 *     schema="UnifiedLocation",
 *     type="object",
 *     title="Unified Location",
 *     description="Unified location data from either WDTP database or OpenStreetMap",
 *
 *     @OA\Property(property="source", type="string", enum={"wdtp", "osm"}, description="Location data source", example="wdtp"),
 *     @OA\Property(property="location_id", type="integer", nullable=true, description="WDTP location ID (null for OSM locations)", example=42),
 *     @OA\Property(property="osm_id", type="string", nullable=true, description="OpenStreetMap ID in format 'node/123' or 'way/456' (null for WDTP locations)", example="node/123456789"),
 *     @OA\Property(property="osm_type", type="string", nullable=true, enum={"node", "way"}, description="OpenStreetMap element type (null for WDTP locations)", example="node"),
 *     @OA\Property(property="name", type="string", description="Location name", example="Starbucks - Times Square"),
 *     @OA\Property(property="latitude", type="number", format="float", description="Location latitude", example=40.7580),
 *     @OA\Property(property="longitude", type="number", format="float", description="Location longitude", example=-73.9855),
 *     @OA\Property(property="has_wage_data", type="boolean", description="Whether location has wage reports (always false for OSM)", example=true),
 *     @OA\Property(property="wage_reports_count", type="integer", description="Number of wage reports (always 0 for OSM)", example=12),
 *     @OA\Property(property="address", type="string", description="Formatted address", example="1556 Broadway, New York, NY 10036"),
 *     @OA\Property(property="tags", type="object", nullable=true, description="Raw OpenStreetMap tags (null for WDTP locations)", example={"amenity": "restaurant", "cuisine": "pizza"}),
 *     @OA\Property(
 *         property="organization",
 *         ref="#/components/schemas/Organization",
 *         nullable=true,
 *         description="Associated organization (null for OSM locations)"
 *     ),
 *     @OA\Property(property="distance_meters", type="number", format="float", nullable=true, description="Distance from search center in meters", example=245.8),
 *     @OA\Property(property="relevance_score", type="number", format="float", nullable=true, description="Search relevance score (0-1)", example=0.92)
 * )
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

            // Raw OSM tags
            'tags' => $this->when($isOsmLocation, $this->tags),

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
