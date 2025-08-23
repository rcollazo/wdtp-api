<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class IndustryNodeResource extends IndustryResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'children' => $this->whenLoaded('children', function () use ($request) {
                return IndustryNodeResource::collection($this->children)->toArray($request);
            }, []),
        ]);
    }
}
