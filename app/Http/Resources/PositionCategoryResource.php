<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="PositionCategory",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Barista"),
 *     @OA\Property(property="slug", type="string", example="barista"),
 *     @OA\Property(property="description", type="string", nullable=true, example="Prepares and serves coffee drinks"),
 *     @OA\Property(property="status", type="string", enum={"active", "inactive"}, example="active"),
 *     @OA\Property(property="industry", type="object", nullable=true,
 *         @OA\Property(property="id", type="integer", example=1),
 *         @OA\Property(property="name", type="string", example="Food Service"),
 *         @OA\Property(property="slug", type="string", example="food-service")
 *     ),
 *     @OA\Property(property="created_at", type="string", format="datetime", example="2024-08-26T10:30:00Z"),
 *     @OA\Property(property="updated_at", type="string", format="datetime", example="2024-08-26T10:30:00Z")
 * )
 */
class PositionCategoryResource extends JsonResource
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
            'description' => $this->description,
            'status' => $this->status,
            'industry' => $this->whenLoaded('industry', function () {
                return [
                    'id' => $this->industry->id,
                    'name' => $this->industry->name,
                    'slug' => $this->industry->slug,
                ];
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
