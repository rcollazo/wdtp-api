<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WageReportListItemResource extends JsonResource
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
            'employment_type_display' => $this->employment_type_display,
            'normalized_hourly_money' => $this->normalizedHourlyMoney(),
            'currency' => $this->currency,
            'effective_date' => $this->effective_date,
            'tips_included' => $this->tips_included,
            'created_at' => $this->created_at->format('Y-m-d'),

            // Minimal location info
            'location' => $this->whenLoaded('location', function () {
                return [
                    'id' => $this->location->id,
                    'name' => $this->location->name,
                    'city' => $this->location->city,
                    'state_province' => $this->location->state_province,
                ];
            }),

            // Minimal organization info
            'organization' => $this->whenLoaded('organization', function () {
                return [
                    'id' => $this->organization->id,
                    'name' => $this->organization->name,
                    'slug' => $this->organization->slug,
                ];
            }),

            // Include distance if present
            'distance_meters' => $this->when(
                isset($this->distance_meters),
                round($this->distance_meters)
            ),
        ];
    }
}
