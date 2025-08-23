<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\IndustryNodeResource;
use App\Http\Resources\IndustryResource;
use App\Models\Industry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;

class IndustryController extends Controller
{
    /**
     * Display a listing of industries.
     */
    public function index(Request $request): AnonymousResourceCollection|JsonResource
    {
        $tree = $request->boolean('tree');
        $search = $request->string('q')->value();
        $perPage = min($request->integer('per_page', 25), 100);

        if ($tree) {
            // Return cached tree structure
            $cacheKey = 'industries:'.$this->getCacheVersion().':tree';

            return Cache::remember($cacheKey, 300, function () use ($search) {
                $query = Industry::query()
                    ->defaultFilters()
                    ->with(['parent'])
                    ->withCount(['children' => function ($query) {
                        $query->defaultFilters();
                    }])
                    ->orderBy('sort');

                if ($search) {
                    $query->search($search);
                }

                $industries = $query->get();

                // Load children after getting the main collection to avoid N+1
                $industries->load(['children' => function ($query) {
                    $query->defaultFilters()->orderBy('sort');
                }]);

                $tree = Industry::buildTree($industries);

                return IndustryNodeResource::collection($tree);
            });
        }

        // Return flat paginated list (not cached)
        $query = Industry::query()
            ->defaultFilters()
            ->with(['parent'])
            ->withCount(['children' => function ($query) {
                $query->defaultFilters();
            }])
            ->orderBy('sort');

        if ($search) {
            $query->search($search);
        }

        $industries = $query->paginate($perPage);

        return IndustryResource::collection($industries);
    }

    /**
     * Get autocomplete suggestions for industries.
     */
    public function autocomplete(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:2',
            'limit' => 'sometimes|integer|min:1|max:50',
        ]);

        $search = $request->string('q')->value();
        $limit = min($request->integer('limit', 10), 50);

        $cacheKey = 'industries:'.$this->getCacheVersion().':ac:'.md5($search.$limit);

        $results = Cache::remember($cacheKey, 600, function () use ($search, $limit) {
            return Industry::query()
                ->defaultFilters()
                ->search($search)
                ->orderBy('sort')
                ->limit($limit)
                ->get()
                ->map(function (Industry $industry) {
                    return [
                        'id' => $industry->id,
                        'name' => $industry->name,
                        'slug' => $industry->slug,
                        'breadcrumbs_text' => $industry->getFullPath(),
                    ];
                });
        });

        return response()->json($results);
    }

    /**
     * Display the specified industry.
     */
    public function show(string $idOrSlug): JsonResource
    {
        $cacheKey = 'industries:'.$this->getCacheVersion().':show:'.$idOrSlug;

        $industry = Cache::remember($cacheKey, 300, function () use ($idOrSlug) {
            return Industry::query()
                ->defaultFilters()
                ->with(['parent'])
                ->withCount(['children' => function ($query) {
                    $query->defaultFilters();
                }])
                ->where(function ($query) use ($idOrSlug) {
                    if (is_numeric($idOrSlug)) {
                        $query->where('id', $idOrSlug);
                    } else {
                        $query->where('slug', $idOrSlug);
                    }
                })
                ->firstOrFail();
        });

        return new IndustryResource($industry);
    }

    /**
     * Get the current cache version for industries.
     */
    private function getCacheVersion(): int
    {
        return Cache::get('industries:ver', 1);
    }
}
