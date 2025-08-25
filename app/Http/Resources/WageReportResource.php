<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="WageReport",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="job_title", type="string"),
 *     @OA\Property(property="employment_type", type="string"),
 *     @OA\Property(property="employment_type_display", type="string"),
 *     @OA\Property(property="wage_period", type="string"),
 *     @OA\Property(property="wage_period_display", type="string"),
 *     @OA\Property(property="amount_cents", type="integer"),
 *     @OA\Property(property="normalized_hourly_cents", type="integer"),
 *     @OA\Property(property="currency", type="string"),
 *     @OA\Property(property="hours_per_week", type="integer", nullable=true),
 *     @OA\Property(property="effective_date", type="string", format="date", nullable=true),
 *     @OA\Property(property="tips_included", type="boolean"),
 *     @OA\Property(property="unionized", type="boolean", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="datetime"),
 *     @OA\Property(property="location", ref="#/components/schemas/Location"),
 *     @OA\Property(property="organization", ref="#/components/schemas/Organization"),
 *     @OA\Property(property="original_amount_money", type="string"),
 *     @OA\Property(property="normalized_hourly_money", type="string"),
 *     @OA\Property(property="distance_meters", type="integer", nullable=true)
 * )
 */
class WageReportResource extends JsonResource
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
            'job_title' => $this->job_title,
            'employment_type' => $this->employment_type,
            'employment_type_display' => $this->employment_type_display,
            'wage_period' => $this->wage_period,
            'wage_period_display' => $this->wage_period_display,
            'amount_cents' => $this->amount_cents,
            'normalized_hourly_cents' => $this->normalized_hourly_cents,
            'currency' => $this->currency,
            'hours_per_week' => $this->hours_per_week,
            'effective_date' => $this->effective_date,
            'tips_included' => $this->tips_included,
            'unionized' => $this->unionized,
            'created_at' => $this->created_at,

            // Relationships
            'location' => new LocationResource($this->whenLoaded('location')),
            'organization' => new OrganizationResource($this->whenLoaded('organization')),

            // Computed fields
            'original_amount_money' => $this->originalAmountMoney(),
            'normalized_hourly_money' => $this->normalizedHourlyMoney(),

            // Include distance if present (from spatial queries)
            'distance_meters' => $this->when(
                isset($this->distance_meters),
                round($this->distance_meters)
            ),
        ];
    }
}
