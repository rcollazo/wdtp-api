<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationListItemResource extends JsonResource
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
            'domain' => $this->domain,
            'primary_industry' => $this->when($this->relationLoaded('primaryIndustry'),
                fn () => $this->primaryIndustry ? [
                    'id' => $this->primaryIndustry->id,
                    'name' => $this->primaryIndustry->name,
                    'slug' => $this->primaryIndustry->slug,
                ] : null
            ),
            'locations_count' => $this->locations_count,
            'wage_reports_count' => $this->wage_reports_count,
            'is_verified' => $this->verification_status === 'verified',
        ];
    }
}
