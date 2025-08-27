<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="Location",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Times Square Location"),
 *     @OA\Property(property="slug", type="string", example="times-square-location"),
 *     @OA\Property(property="address_line_1", type="string", example="1234 Main Street"),
 *     @OA\Property(property="address_line_2", type="string", nullable=true, example="Suite 100"),
 *     @OA\Property(property="city", type="string", example="New York"),
 *     @OA\Property(property="state_province", type="string", example="NY"),
 *     @OA\Property(property="postal_code", type="string", example="10001"),
 *     @OA\Property(property="country_code", type="string", example="US"),
 *     @OA\Property(property="phone", type="string", nullable=true, example="+1-555-123-4567"),
 *     @OA\Property(property="website_url", type="string", nullable=true, example="https://example.com/location"),
 *     @OA\Property(property="description", type="string", nullable=true, example="Flagship location in Times Square"),
 *     @OA\Property(property="latitude", type="number", format="float", example=40.7128),
 *     @OA\Property(property="longitude", type="number", format="float", example=-74.0060),
 *     @OA\Property(property="is_active", type="boolean", example=true),
 *     @OA\Property(property="is_verified", type="boolean", example=true),
 *     @OA\Property(property="created_at", type="string", format="datetime", example="2024-08-26T10:30:00Z"),
 *     @OA\Property(property="organization", ref="#/components/schemas/Organization", nullable=true),
 *     @OA\Property(property="distance_meters", type="integer", nullable=true, description="Distance in meters from search coordinates", example=1500)
 * )
 */
class LocationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'city' => $this->city,
            'state_province' => $this->state_province,
            'postal_code' => $this->postal_code,
            'country_code' => $this->country_code,
            'phone' => $this->phone,
            'website_url' => $this->website_url,
            'description' => $this->description,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_active' => $this->is_active,
            'is_verified' => $this->is_verified,
            'created_at' => $this->created_at,

            // Organization relationship
            'organization' => new OrganizationResource($this->whenLoaded('organization')),

            // Include distance if present (from spatial queries)
            'distance_meters' => $this->when(
                isset($this->distance_meters),
                round($this->distance_meters)
            ),
        ];
    }
}
