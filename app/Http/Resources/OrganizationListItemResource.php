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
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'domain' => $this->domain,
            'locations_count' => $this->locations_count,
            'wage_reports_count' => $this->wage_reports_count,
            'is_verified' => $this->verification_status === 'verified',
        ];

        // Only include primary_industry if the relationship is loaded
        if ($this->relationLoaded('primaryIndustry')) {
            $data['primary_industry'] = $this->primaryIndustry ? [
                'id' => $this->primaryIndustry->id,
                'name' => $this->primaryIndustry->name,
                'slug' => $this->primaryIndustry->slug,
            ] : null;
        }

        return $data;
    }
}
