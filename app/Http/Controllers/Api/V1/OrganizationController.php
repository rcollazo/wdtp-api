<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationListItemResource;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\WageStatisticsResource;
use App\Models\Organization;
use App\Services\WageStatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;

/**
 * @OA\Schema(
 *     schema="Organization",
 *     type="object",
 *
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", maxLength=120, example="Starbucks"),
 *     @OA\Property(property="slug", type="string", example="starbucks"),
 *     @OA\Property(property="legal_name", type="string", maxLength=255, example="Starbucks Corporation"),
 *     @OA\Property(property="domain", type="string", example="starbucks.com"),
 *     @OA\Property(property="website_url", type="string", format="url", example="https://starbucks.com"),
 *     @OA\Property(property="description", type="string", nullable=true, example="Global coffee chain"),
 *     @OA\Property(property="logo_url", type="string", format="url", nullable=true, example="https://example.com/logo.png"),
 *     @OA\Property(
 *         property="primary_industry",
 *         type="object",
 *         nullable=true,
 *         @OA\Property(property="id", type="integer", example=5),
 *         @OA\Property(property="name", type="string", example="Coffee Shop"),
 *         @OA\Property(property="slug", type="string", example="coffee-shop")
 *     ),
 *     @OA\Property(property="locations_count", type="integer", example=8450),
 *     @OA\Property(property="wage_reports_count", type="integer", example=2341),
 *     @OA\Property(property="is_verified", type="boolean", example=true),
 *     @OA\Property(property="verified_at", type="string", format="date-time", nullable=true, example="2024-01-15T10:30:00Z"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2023-12-01T08:15:00Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2024-01-15T10:30:00Z")
 * )
 *
 * @OA\Schema(
 *     schema="OrganizationListItem",
 *     type="object",
 *
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Starbucks"),
 *     @OA\Property(property="slug", type="string", example="starbucks"),
 *     @OA\Property(property="domain", type="string", example="starbucks.com"),
 *     @OA\Property(
 *         property="primary_industry",
 *         type="object",
 *         nullable=true,
 *         @OA\Property(property="id", type="integer", example=5),
 *         @OA\Property(property="name", type="string", example="Coffee Shop"),
 *         @OA\Property(property="slug", type="string", example="coffee-shop")
 *     ),
 *     @OA\Property(property="locations_count", type="integer", example=8450),
 *     @OA\Property(property="wage_reports_count", type="integer", example=2341),
 *     @OA\Property(property="is_verified", type="boolean", example=true)
 * )
 */
class OrganizationController extends Controller
{
    public function __construct(private WageStatisticsService $statisticsService) {}

    /**
     * @OA\Get(
     *     path="/api/v1/organizations",
     *     summary="List organizations",
     *     description="Get paginated list of organizations with search, filtering and sorting",
     *     tags={"Organizations"},
     *
     *     @OA\Parameter(
     *         name="q",
     *         in="query",
     *         description="Search term for organization name, legal name or domain (case-insensitive)",
     *         required=false,
     *
     *         @OA\Schema(type="string", minLength=2, example="starbucks")
     *     ),
     *
     *     @OA\Parameter(
     *         name="industry_id",
     *         in="query",
     *         description="Filter by primary industry ID",
     *         required=false,
     *
     *         @OA\Schema(type="integer", example=5)
     *     ),
     *
     *     @OA\Parameter(
     *         name="industry_slug",
     *         in="query",
     *         description="Filter by primary industry slug",
     *         required=false,
     *
     *         @OA\Schema(type="string", example="coffee-shop")
     *     ),
     *
     *     @OA\Parameter(
     *         name="verified",
     *         in="query",
     *         description="Filter by verification status",
     *         required=false,
     *
     *         @OA\Schema(type="boolean", example=true)
     *     ),
     *
     *     @OA\Parameter(
     *         name="has_locations",
     *         in="query",
     *         description="Filter organizations that have locations",
     *         required=false,
     *
     *         @OA\Schema(type="boolean", example=true)
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
     *     @OA\Parameter(
     *         name="sort",
     *         in="query",
     *         description="Sort order (relevance only works with search)",
     *         required=false,
     *
     *         @OA\Schema(type="string", enum={"relevance", "name", "locations", "wage_reports", "updated"}, default="name")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Organizations retrieved successfully",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *
     *                 @OA\Items(ref="#/components/schemas/OrganizationListItem")
     *             ),
     *
     *             @OA\Property(
     *                 property="links",
     *                 type="object",
     *                 description="Pagination links"
     *             ),
     *             @OA\Property(
     *                 property="meta",
     *                 type="object",
     *                 description="Pagination metadata"
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        // Validate query parameters with proper boolean handling
        $request->validate([
            'q' => 'sometimes|string|min:2',
            'industry_id' => 'sometimes|integer|exists:industries,id',
            'industry_slug' => 'sometimes|string|exists:industries,slug',
            'verified' => 'sometimes|in:0,1,false,true',
            'has_locations' => 'sometimes|in:0,1,false,true',
            'per_page' => 'sometimes|integer|min:1',
            'sort' => 'sometimes|in:relevance,name,locations,wage_reports,updated',
        ]);

        $search = $request->string('q')->value();
        $industryId = $request->integer('industry_id');
        $industrySlug = $request->string('industry_slug')->value();
        $verified = $request->boolean('verified');
        $hasLocations = $request->boolean('has_locations');
        $perPage = min($request->integer('per_page', 25), 100);
        $sort = $request->string('sort', $search ? 'relevance' : 'name')->value();

        // Build cache key for the query to match test expectations
        $requestedSort = $request->string('sort')->value();
        $cacheParams = [
            'q' => $search ?: null,
            'industry_id' => $industryId ?: null,
            'industry_slug' => $industrySlug ?: null,
            'verified' => $verified,
            'has_locations' => $hasLocations,
            'per_page' => $perPage,
            'sort' => $requestedSort ?: null,
        ];
        $cacheKey = 'orgs:'.$this->getCacheVersion().':index:'.md5(json_encode($cacheParams));

        $organizations = Cache::remember($cacheKey, 300, function () use ($search, $industryId, $industrySlug, $verified, $hasLocations, $sort, $perPage, $request) {
            $query = Organization::defaultFilters();

            // Add search
            if ($search) {
                $query->search($search);
            }

            // Add industry filter
            if ($industryId) {
                $query->where('primary_industry_id', $industryId);
            } elseif ($industrySlug) {
                $query->inIndustry($industrySlug);
            }

            // Add verification filter (only if explicitly requested)
            if ($request->has('verified')) {
                if ($verified) {
                    $query->verified();
                } else {
                    $query->where('verification_status', '!=', 'verified');
                }
            }

            // Add has_locations filter (only if explicitly requested)
            if ($request->has('has_locations')) {
                if ($hasLocations) {
                    $query->hasLocations();
                } else {
                    $query->where('locations_count', '=', 0);
                }
            }

            // Add sorting
            $this->applySorting($query, $sort, $search);

            // Add eager loading
            $query->with(['primaryIndustry']);

            return $query->paginate($perPage);
        });

        return OrganizationListItemResource::collection($organizations);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/organizations/{idOrSlug}",
     *     summary="Get single organization",
     *     description="Retrieve a specific organization by ID or slug with full details",
     *     tags={"Organizations"},
     *
     *     @OA\Parameter(
     *         name="idOrSlug",
     *         in="path",
     *         description="Organization ID (integer) or slug (string)",
     *         required=true,
     *
     *         @OA\Schema(
     *             oneOf={
     *                 @OA\Schema(type="integer", example=1),
     *                 @OA\Schema(type="string", example="starbucks")
     *             }
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Organization retrieved successfully",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Organization")
     *         )
     *     ),
     *
     *     @OA\Response(response=404, description="Organization not found")
     * )
     */
    public function show(string $idOrSlug): JsonResource
    {
        $cacheKey = 'orgs:'.$this->getCacheVersion().':show:'.$idOrSlug;

        $organization = Cache::remember($cacheKey, 300, function () use ($idOrSlug) {
            return Organization::query()
                ->defaultFilters()
                ->with(['primaryIndustry'])
                ->where(function ($query) use ($idOrSlug) {
                    if (is_numeric($idOrSlug)) {
                        $query->where('id', $idOrSlug);
                    } else {
                        $query->where('slug', $idOrSlug);
                    }
                })
                ->firstOrFail();
        });

        return new OrganizationResource($organization);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/organizations/{idOrSlug}/wage-stats",
     *     summary="Get wage statistics for organization",
     *     description="Retrieve wage statistics across all locations for a specific organization",
     *     tags={"Organizations"},
     *
     *     @OA\Parameter(
     *         name="idOrSlug",
     *         in="path",
     *         description="Organization ID (integer) or slug (string)",
     *         required=true,
     *
     *         @OA\Schema(
     *             oneOf={
     *                 @OA\Schema(type="integer", example=1),
     *                 @OA\Schema(type="string", example="starbucks")
     *             }
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Organization wage statistics",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="data", ref="#/components/schemas/WageStatistics")
     *         )
     *     ),
     *
     *     @OA\Response(response=404, description="Organization not found"),
     *     @OA\Response(response=422, description="No wage data available for this organization")
     * )
     */
    public function wageStats(string $idOrSlug): JsonResource
    {
        // First, find the organization to get its ID
        $organization = Organization::query()
            ->defaultFilters()
            ->where(function ($query) use ($idOrSlug) {
                if (is_numeric($idOrSlug)) {
                    $query->where('id', $idOrSlug);
                } else {
                    $query->where('slug', $idOrSlug);
                }
            })
            ->firstOrFail();

        $statistics = $this->statisticsService->getOrganizationStatistics($organization->id);

        // Return 422 if no wage data available
        if ($statistics['count'] === 0) {
            abort(422, 'No wage data available for this organization');
        }

        return new WageStatisticsResource($statistics);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/organizations/autocomplete",
     *     summary="Organization autocomplete search",
     *     description="Fast autocomplete endpoint for organization search with minimal response format",
     *     tags={"Organizations"},
     *
     *     @OA\Parameter(
     *         name="q",
     *         in="query",
     *         required=true,
     *         description="Search query (minimum 2 characters)",
     *
     *         @OA\Schema(type="string", minLength=2, example="starb")
     *     ),
     *
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Maximum number of results (default 10, max 50)",
     *
     *         @OA\Schema(type="integer", minimum=1, maximum=50, default=10, example=10)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Autocomplete suggestions",
     *
     *         @OA\JsonContent(
     *             type="array",
     *
     *             @OA\Items(
     *
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Starbucks"),
     *                 @OA\Property(property="slug", type="string", example="starbucks")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function autocomplete(Request $request): JsonResponse
    {
        // Validate input parameters
        $request->validate([
            'q' => 'required|string|min:2',
            'limit' => 'integer|min:1|max:50',
        ]);

        $query = $request->string('q')->value();
        $limit = min($request->integer('limit', 10), 50);

        // Build cache key with query hash
        $cacheParams = ['q' => $query, 'limit' => $limit];
        $cacheKey = 'orgs:'.$this->getCacheVersion().':ac:'.md5(json_encode($cacheParams));

        $results = Cache::remember($cacheKey, 600, function () use ($query, $limit) {
            return Organization::query()
                ->defaultFilters()
                ->search($query)
                ->select(['id', 'name', 'slug'])
                ->limit($limit)
                ->get()
                ->toArray();
        });

        return response()->json($results);
    }

    /**
     * Apply sorting to the query based on the sort parameter.
     */
    private function applySorting($query, string $sort, ?string $search): void
    {
        // If searching, don't add additional ordering as search scope already provides relevance ordering
        if ($search && $sort === 'relevance') {
            return; // Search scope already applies relevance ordering
        }

        // If searching but a different sort is requested, we need to override the search ordering
        if ($search && $sort !== 'relevance') {
            // Clear the existing order from search scope and apply new one
            $query->reorder();
        }

        match ($sort) {
            'relevance' => $query->orderBy('name'), // Fallback when no search
            'name' => $query->orderBy('name'),
            'locations' => $query->orderByDesc('locations_count')->orderBy('name'),
            'wage_reports' => $query->orderByDesc('wage_reports_count')->orderBy('name'),
            'updated' => $query->orderByDesc('updated_at'),
            default => $query->orderBy('name'),
        };
    }

    /**
     * Get the current cache version for organizations.
     */
    private function getCacheVersion(): int
    {
        return Cache::get('orgs:ver', 1);
    }
}
