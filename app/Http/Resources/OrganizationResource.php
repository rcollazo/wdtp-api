<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="Organization",
 *
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Starbucks Corporation"),
 *     @OA\Property(property="slug", type="string", example="starbucks-corporation"),
 *     @OA\Property(property="domain", type="string", nullable=true, example="starbucks.com"),
 *     @OA\Property(property="locations_count", type="integer", example=150),
 *     @OA\Property(property="wage_reports_count", type="integer", example=42),
 *     @OA\Property(property="is_verified", type="boolean", example=true),
 *     @OA\Property(property="primary_industry", type="object", nullable=true,
 *         @OA\Property(property="id", type="integer", example=1),
 *         @OA\Property(property="name", type="string", example="Food Service"),
 *         @OA\Property(property="slug", type="string", example="food-service")
 *     ),
 *     @OA\Property(property="legal_name", type="string", nullable=true, example="Starbucks Corporation"),
 *     @OA\Property(property="website_url", type="string", nullable=true, example="https://www.starbucks.com"),
 *     @OA\Property(property="description", type="string", nullable=true, example="Global coffeehouse chain"),
 *     @OA\Property(property="logo_url", type="string", nullable=true, example="https://example.com/logos/starbucks.png"),
 *     @OA\Property(property="verified_at", type="string", format="datetime", nullable=true, example="2024-08-26T10:30:00Z"),
 *     @OA\Property(property="created_at", type="string", format="datetime", example="2024-08-26T10:30:00Z"),
 *     @OA\Property(property="updated_at", type="string", format="datetime", example="2024-08-26T10:30:00Z")
 * )
 */
class OrganizationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(
            (new OrganizationListItemResource($this->resource))->toArray($request),
            [
                'legal_name' => $this->legal_name,
                'website_url' => $this->website_url,
                'description' => $this->description,
                'logo_url' => $this->logo_url,
                'verified_at' => $this->verified_at?->toISOString(),
                'created_at' => $this->created_at->toISOString(),
                'updated_at' => $this->updated_at->toISOString(),
            ]
        );
    }
}
