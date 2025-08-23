<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
