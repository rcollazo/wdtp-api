<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IndustryResource extends JsonResource
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
            'depth' => $this->depth,
            'sort' => $this->sort,
            'parent' => $this->whenLoaded('parent', function () {
                return [
                    'id' => $this->parent->id,
                    'name' => $this->parent->name,
                    'slug' => $this->parent->slug,
                ];
            }),
            'breadcrumbs' => $this->breadcrumbs()->map(function ($crumb) {
                return [
                    'name' => $crumb->name,
                    'slug' => $crumb->slug,
                ];
            })->toArray(),
            'children_count' => $this->children()->defaultFilters()->count(),
        ];
    }
}
