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

/**
 * @OA\Schema(
 *     schema="Industry",
 *     type="object",
 *
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", maxLength=120, example="Fast Food Restaurant"),
 *     @OA\Property(property="slug", type="string", example="fast-food-restaurant"),
 *     @OA\Property(property="depth", type="integer", minimum=0, maximum=6, example=1),
 *     @OA\Property(property="sort", type="integer", example=10),
 *     @OA\Property(
 *         property="parent",
 *         type="object",
 *         nullable=true,
 *         @OA\Property(property="id", type="integer", example=1),
 *         @OA\Property(property="name", type="string", example="Restaurants"),
 *         @OA\Property(property="slug", type="string", example="restaurants")
 *     ),
 *     @OA\Property(
 *         property="breadcrumbs",
 *         type="array",
 *
 *         @OA\Items(ref="#/components/schemas/Breadcrumb")
 *     ),
 *
 *     @OA\Property(property="children_count", type="integer", example=3)
 * )
 *
 * @OA\Schema(
 *     schema="IndustryNode",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/Industry"),
 *         @OA\Schema(
 *
 *             @OA\Property(
 *                 property="children",
 *                 type="array",
 *
 *                 @OA\Items(ref="#/components/schemas/IndustryNode")
 *             )
 *         )
 *     }
 * )
 *
 * @OA\Schema(
 *     schema="Breadcrumb",
 *     type="object",
 *
 *     @OA\Property(property="name", type="string", example="Restaurants"),
 *     @OA\Property(property="slug", type="string", example="restaurants")
 * )
 */
class IndustryController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/industries",
     *     summary="List industries",
     *     description="Get industries as nested tree or flat paginated list with optional search",
     *     tags={"Industries"},
     *
     *     @OA\Parameter(
     *         name="tree",
     *         in="query",
     *         description="Return nested tree structure instead of flat list",
     *         required=false,
     *
     *         @OA\Schema(type="boolean", default=false)
     *     ),
     *
     *     @OA\Parameter(
     *         name="q",
     *         in="query",
     *         description="Search term for industry name or slug (case-insensitive)",
     *         required=false,
     *
     *         @OA\Schema(type="string", minLength=2, example="restaurant")
     *     ),
     *
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Items per page for flat list (ignored for tree view)",
     *         required=false,
     *
     *         @OA\Schema(type="integer", minimum=1, maximum=100, default=25)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Industries retrieved successfully",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     oneOf={
     *
     *                         @OA\Schema(ref="#/components/schemas/Industry"),
     *                         @OA\Schema(ref="#/components/schemas/IndustryNode")
     *                     }
     *                 )
     *             ),
     *
     *             @OA\Property(
     *                 property="links",
     *                 type="object",
     *                 description="Pagination links (only for flat list)"
     *             ),
     *             @OA\Property(
     *                 property="meta",
     *                 type="object",
     *                 description="Pagination metadata (only for flat list)"
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error")
     * )
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
     * @OA\Get(
     *     path="/api/v1/industries/autocomplete",
     *     summary="Industry autocomplete search",
     *     description="Fast search for industries with minimal response payload",
     *     tags={"Industries"},
     *
     *     @OA\Parameter(
     *         name="q",
     *         in="query",
     *         description="Search term (minimum 2 characters)",
     *         required=true,
     *
     *         @OA\Schema(type="string", minLength=2, example="food")
     *     ),
     *
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Maximum number of results",
     *         required=false,
     *
     *         @OA\Schema(type="integer", minimum=1, maximum=50, default=10)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Autocomplete results",
     *
     *         @OA\JsonContent(
     *             type="array",
     *
     *             @OA\Items(
     *                 type="object",
     *
     *                 @OA\Property(property="id", type="integer", example=2),
     *                 @OA\Property(property="name", type="string", example="Fast Food Restaurant"),
     *                 @OA\Property(property="slug", type="string", example="fast-food-restaurant"),
     *                 @OA\Property(property="breadcrumbs_text", type="string", example="Restaurants > Fast Food Restaurant")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error - missing or invalid parameters")
     * )
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
     * @OA\Get(
     *     path="/api/v1/industries/{idOrSlug}",
     *     summary="Get single industry",
     *     description="Retrieve a specific industry by ID or slug with relationships",
     *     tags={"Industries"},
     *
     *     @OA\Parameter(
     *         name="idOrSlug",
     *         in="path",
     *         description="Industry ID (integer) or slug (string)",
     *         required=true,
     *
     *         @OA\Schema(
     *             oneOf={
     *                 @OA\Schema(type="integer", example=1),
     *                 @OA\Schema(type="string", example="fast-food-restaurant")
     *             }
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Industry retrieved successfully",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Industry")
     *         )
     *     ),
     *
     *     @OA\Response(response=404, description="Industry not found")
     * )
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
