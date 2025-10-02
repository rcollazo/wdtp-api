<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LocationIndexRequest;
use App\Http\Requests\LocationSearchRequest;
use App\Http\Resources\LocationResource;
use App\Http\Resources\UnifiedLocationResource;
use App\Http\Resources\WageStatisticsResource;
use App\Models\Location;
use App\Services\RelevanceScorer;
use App\Services\WageStatisticsService;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Tag(
 *     name="Locations",
 *     description="Location management and wage statistics"
 * )
 */
class LocationController extends Controller
{
    public function __construct(
        private WageStatisticsService $statisticsService,
        private RelevanceScorer $relevanceScorer
    ) {}

    /**
     * @OA\Get(
     *     path="/api/v1/locations",
     *     summary="List locations with spatial search",
     *     description="Get paginated list of locations with optional spatial filtering",
     *     tags={"Locations"},
     *
     *     @OA\Parameter(
     *         name="near",
     *         in="query",
     *         description="Coordinates in format 'latitude,longitude' for spatial search",
     *         required=false,
     *
     *         @OA\Schema(type="string", example="40.7128,-74.0060")
     *     ),
     *
     *     @OA\Parameter(
     *         name="radius_km",
     *         in="query",
     *         description="Search radius in kilometers (0.1-50km, default: 10)",
     *         required=false,
     *
     *         @OA\Schema(type="number", format="float", minimum=0.1, maximum=50, default=10)
     *     ),
     *
     *     @OA\Parameter(
     *         name="organization_id",
     *         in="query",
     *         description="Filter by organization ID",
     *         required=false,
     *
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Results per page (1-100, default: 20)",
     *         required=false,
     *
     *         @OA\Schema(type="integer", minimum=1, maximum=100, default=20)
     *     ),
     *
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number",
     *         required=false,
     *
     *         @OA\Schema(type="integer", minimum=1, default=1)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Paginated list of locations",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *
     *                 @OA\Items(ref="#/components/schemas/Location")
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
    public function index(LocationIndexRequest $request): JsonResource
    {
        $query = Location::with('organization')->active();

        // Apply spatial filtering if coordinates provided
        if ($request->has('near')) {
            [$lat, $lng] = explode(',', $request->near);
            $radiusKm = $request->get('radius_km', 10);

            $query = $query->near($lat, $lng, $radiusKm)
                ->withDistance($lat, $lng)
                ->orderByDistance($lat, $lng);
        }

        // Apply organization filtering
        if ($request->has('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }

        $perPage = $request->get('per_page', 20);

        return LocationResource::collection(
            $query->paginate($perPage)
        );
    }

    /**
     * Search locations with text and spatial filtering (WDTP-only for now).
     *
     * Queries WDTP locations using full-text search and spatial filtering,
     * calculates relevance scores, and returns sorted, paginated results.
     * OSM integration will be added in a later task.
     */
    public function search(LocationSearchRequest $request): JsonResource
    {
        // Extract validated parameters
        $searchQuery = $request->q;
        $lat = $request->lat;
        $lng = $request->lng;
        $radiusKm = $request->get('radius_km', 10);
        $minWageReports = $request->min_wage_reports;
        $perPage = $request->get('per_page', 100);
        $page = $request->get('page', 1);

        // Build WDTP location query
        $query = Location::with('organization')
            ->active()
            ->searchByNameOrCategory($searchQuery)
            ->near($lat, $lng, $radiusKm)
            ->withDistance($lat, $lng);

        // Apply min_wage_reports filter if provided
        if ($minWageReports !== null) {
            $query->whereHas('wageReports', function ($q) use ($minWageReports) {
                $q->select(DB::raw('1'))
                    ->groupBy('location_id')
                    ->havingRaw('count(*) >= ?', [$minWageReports]);
            });
        }

        // Get all results to calculate relevance scores
        $locations = $query->get();

        // Calculate relevance score for each location
        foreach ($locations as $location) {
            $location->relevance_score = $this->relevanceScorer->calculate($location, $radiusKm);
        }

        // Sort by relevance score descending
        $sorted = $locations->sortByDesc('relevance_score')->values();

        // Manual pagination
        $offset = ($page - 1) * $perPage;
        $paginated = new LengthAwarePaginator(
            $sorted->slice($offset, $perPage)->values(),
            $sorted->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Build comprehensive meta object
        $meta = [
            'total' => $sorted->count(),
            'wdtp_count' => $sorted->count(),
            'osm_count' => 0, // OSM integration in Task 4.2
            'search_query' => $searchQuery,
            'search_type' => $this->detectSearchType($searchQuery),
            'center' => [
                'lat' => $lat,
                'lng' => $lng,
            ],
            'radius_km' => $radiusKm,
        ];

        return UnifiedLocationResource::collection($paginated)
            ->additional(['meta' => $meta]);
    }

    /**
     * Detect search type based on query pattern.
     *
     * Simple heuristic: common category keywords indicate category search,
     * otherwise assume name-based search.
     */
    private function detectSearchType(string $query): string
    {
        $categoryKeywords = [
            'restaurant', 'cafe', 'coffee', 'store', 'shop', 'market',
            'hospital', 'clinic', 'pharmacy', 'hotel', 'motel', 'inn',
            'bank', 'gas', 'station', 'grocery', 'retail', 'bar', 'pub',
        ];

        $queryLower = strtolower($query);

        foreach ($categoryKeywords as $keyword) {
            if (str_contains($queryLower, $keyword)) {
                return 'category';
            }
        }

        return 'name';
    }

    /**
     * @OA\Get(
     *     path="/api/v1/locations/{locationId}/wage-stats",
     *     summary="Get wage statistics for location",
     *     description="Retrieve wage statistics for a specific location",
     *     tags={"Locations"},
     *
     *     @OA\Parameter(
     *         name="locationId",
     *         in="path",
     *         description="Location ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Location wage statistics",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="data", ref="#/components/schemas/WageStatistics")
     *         )
     *     ),
     *
     *     @OA\Response(response=404, description="Location not found"),
     *     @OA\Response(response=422, description="No wage data available for this location")
     * )
     */
    public function wageStats(int $locationId): JsonResource
    {
        // Verify location exists
        Location::findOrFail($locationId);

        $statistics = $this->statisticsService->getLocationStatistics($locationId);

        // Return 422 if no wage data available
        if ($statistics['count'] === 0) {
            abort(422, 'No wage data available for this location');
        }

        return new WageStatisticsResource($statistics);
    }
}
