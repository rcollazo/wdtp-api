<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PositionCategoryResource;
use App\Models\PositionCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;

/**
 * @OA\Schema(
 *     schema="PositionCategory",
 *     type="object",
 *
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", maxLength=120, example="Server"),
 *     @OA\Property(property="slug", type="string", example="server-restaurants"),
 *     @OA\Property(property="description", type="string", nullable=true, example="Takes customer orders, serves food and beverages"),
 *     @OA\Property(property="status", type="string", enum={"active", "inactive"}, example="active"),
 *     @OA\Property(
 *         property="industry",
 *         type="object",
 *         @OA\Property(property="id", type="integer", example=1),
 *         @OA\Property(property="name", type="string", example="Restaurants"),
 *         @OA\Property(property="slug", type="string", example="restaurants")
 *     ),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class PositionCategoryController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/position-categories",
     *     summary="List position categories",
     *     description="Get position categories with optional industry filtering and search",
     *     tags={"Position Categories"},
     *
     *     @OA\Parameter(
     *         name="industry",
     *         in="query",
     *         description="Filter by industry ID or slug",
     *         required=false,
     *
     *         @OA\Schema(
     *             oneOf={
     *                 @OA\Schema(type="integer", example=1),
     *                 @OA\Schema(type="string", example="restaurants")
     *             }
     *         )
     *     ),
     *
     *     @OA\Parameter(
     *         name="q",
     *         in="query",
     *         description="Search term for position name or description (case-insensitive)",
     *         required=false,
     *
     *         @OA\Schema(type="string", minLength=2, example="server")
     *     ),
     *
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by status (defaults to active only)",
     *         required=false,
     *
     *         @OA\Schema(type="string", enum={"active", "inactive", "all"}, default="active")
     *     ),
     *
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Items per page",
     *         required=false,
     *
     *         @OA\Schema(type="integer", minimum=1, maximum=100, default=25)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Position categories retrieved successfully",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *
     *                 @OA\Items(ref="#/components/schemas/PositionCategory")
     *             ),
     *
     *             @OA\Property(property="links", type="object", description="Pagination links"),
     *             @OA\Property(property="meta", type="object", description="Pagination metadata")
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'industry' => 'sometimes|string|max:255',
            'q' => 'sometimes|string|min:2|max:255',
            'status' => 'sometimes|string|in:active,inactive,all',
            'per_page' => 'sometimes|integer|min:1',
        ]);

        $industry = $request->string('industry')->value();
        $search = $request->string('q')->value();
        $status = $request->string('status', 'active')->value();
        $perPage = min($request->integer('per_page', 25), 100);

        $query = PositionCategory::query()->with(['industry']);

        // Filter by industry (ID or slug)
        if ($industry) {
            if (is_numeric($industry)) {
                $query->where('industry_id', $industry);
            } else {
                $query->whereHas('industry', function ($q) use ($industry) {
                    $q->where('slug', $industry);
                });
            }
        }

        // Filter by status
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        // Search functionality
        if ($search) {
            $query->search($search);
        }

        // Order by industry name, then position name
        $query->leftJoin('industries', 'position_categories.industry_id', '=', 'industries.id')
            ->orderBy('industries.name')
            ->orderBy('position_categories.name')
            ->select('position_categories.*');

        $positionCategories = $query->paginate($perPage);

        return PositionCategoryResource::collection($positionCategories);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/position-categories/autocomplete",
     *     summary="Position category autocomplete search",
     *     description="Fast search for position categories with minimal response payload",
     *     tags={"Position Categories"},
     *
     *     @OA\Parameter(
     *         name="q",
     *         in="query",
     *         description="Search term (minimum 2 characters)",
     *         required=true,
     *
     *         @OA\Schema(type="string", minLength=2, example="server")
     *     ),
     *
     *     @OA\Parameter(
     *         name="industry",
     *         in="query",
     *         description="Filter by industry ID or slug",
     *         required=false,
     *
     *         @OA\Schema(
     *             oneOf={
     *                 @OA\Schema(type="integer", example=1),
     *                 @OA\Schema(type="string", example="restaurants")
     *             }
     *         )
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
     *                 @OA\Property(property="name", type="string", example="Server"),
     *                 @OA\Property(property="slug", type="string", example="server-restaurants"),
     *                 @OA\Property(property="industry_name", type="string", example="Restaurants")
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
            'q' => 'required|string|min:2|max:255',
            'industry' => 'sometimes|string|max:255',
            'limit' => 'sometimes|integer|min:1|max:50',
        ]);

        $search = $request->string('q')->value();
        $industry = $request->string('industry')->value();
        $limit = min($request->integer('limit', 10), 50);

        $cacheKey = 'position-categories:ac:'.md5($search.$industry.$limit);

        $results = Cache::remember($cacheKey, 300, function () use ($search, $industry, $limit) {
            $query = PositionCategory::query()
                ->active()
                ->with(['industry'])
                ->search($search);

            // Filter by industry if provided
            if ($industry) {
                if (is_numeric($industry)) {
                    $query->where('industry_id', $industry);
                } else {
                    $query->whereHas('industry', function ($q) use ($industry) {
                        $q->where('slug', $industry);
                    });
                }
            }

            return $query
                ->leftJoin('industries', 'position_categories.industry_id', '=', 'industries.id')
                ->orderBy('industries.name')
                ->orderBy('position_categories.name')
                ->select('position_categories.*')
                ->limit($limit)
                ->get()
                ->map(function (PositionCategory $position) {
                    return [
                        'id' => $position->id,
                        'name' => $position->name,
                        'slug' => $position->slug,
                        'industry_name' => $position->industry->name,
                    ];
                });
        });

        return response()->json($results);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/position-categories/{idOrSlug}",
     *     summary="Get single position category",
     *     description="Retrieve a specific position category by ID or slug with relationships",
     *     tags={"Position Categories"},
     *
     *     @OA\Parameter(
     *         name="idOrSlug",
     *         in="path",
     *         description="Position Category ID (integer) or slug (string)",
     *         required=true,
     *
     *         @OA\Schema(
     *             oneOf={
     *                 @OA\Schema(type="integer", example=1),
     *                 @OA\Schema(type="string", example="server-restaurants")
     *             }
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Position category retrieved successfully",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="data", ref="#/components/schemas/PositionCategory")
     *         )
     *     ),
     *
     *     @OA\Response(response=404, description="Position category not found")
     * )
     */
    public function show(string $idOrSlug): JsonResource
    {
        $cacheKey = 'position-categories:show:'.$idOrSlug;

        $positionCategory = Cache::remember($cacheKey, 300, function () use ($idOrSlug) {
            $query = PositionCategory::query()->with(['industry']);

            if (is_numeric($idOrSlug)) {
                $query->where('id', $idOrSlug);
            } else {
                $query->where('slug', $idOrSlug);
            }

            return $query->firstOrFail();
        });

        return new PositionCategoryResource($positionCategory);
    }
}
