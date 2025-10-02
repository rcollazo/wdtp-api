<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LocationIndexRequest;
use App\Http\Requests\LocationSearchRequest;
use App\Http\Resources\LocationResource;
use App\Http\Resources\UnifiedLocationResource;
use App\Http\Resources\WageStatisticsResource;
use App\Models\Location;
use App\Services\OverpassService;
use App\Services\RelevanceScorer;
use App\Services\WageStatisticsService;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        private RelevanceScorer $relevanceScorer,
        private OverpassService $overpassService
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
     * Search locations with text and spatial filtering (unified WDTP + OSM).
     *
     * Queries both WDTP database locations and OpenStreetMap POIs,
     * merges results, calculates relevance scores, and returns sorted,
     * paginated unified results. Handles OSM failures gracefully.
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

        // Get WDTP results and limit to prevent OOM
        $wdtpResults = $query->get()->take(1000);

        // Calculate relevance score for each WDTP location
        foreach ($wdtpResults as $location) {
            $location->relevance_score = $this->relevanceScorer->calculate($location, $radiusKm);
        }

        // Initialize OSM results collection and unavailable flag
        $osmResults = collect([]);
        $osmUnavailable = false;

        // Query OSM if include_osm parameter is true
        if ($request->boolean('include_osm')) {
            try {
                // Query OSM POIs and limit to prevent OOM
                $osmResults = $this->overpassService->search($searchQuery, $lat, $lng, $radiusKm)
                    ->take(1000);

                // Calculate distance and relevance for each OSM result
                foreach ($osmResults as $osmLocation) {
                    // Calculate distance using Haversine formula (distance already set by service)
                    // Distance is already calculated in OverpassService, just verify it exists
                    if ($osmLocation->distance_meters === null) {
                        $osmLocation->distance_meters = $this->calculateHaversineDistance(
                            $lat,
                            $lng,
                            $osmLocation->latitude,
                            $osmLocation->longitude
                        );
                    }

                    // Calculate relevance score
                    $osmLocation->relevance_score = $this->relevanceScorer->calculate($osmLocation, $radiusKm);
                }
            } catch (\Exception $e) {
                // Log OSM failure but continue with WDTP results (graceful degradation)
                Log::warning('OSM search failed - continuing with WDTP results only', [
                    'query' => $searchQuery,
                    'error' => $e->getMessage(),
                    'lat' => $lat,
                    'lng' => $lng,
                    'radius_km' => $radiusKm,
                ]);
                $osmUnavailable = true;
            }
        }

        // Merge WDTP and OSM results
        $merged = $wdtpResults->merge($osmResults);

        // Sort merged results by relevance score descending
        $sorted = $merged->sortByDesc('relevance_score')->values();

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
            'wdtp_count' => $wdtpResults->count(),
            'osm_count' => $osmResults->count(),
            'search_query' => $searchQuery,
            'search_type' => $this->detectSearchType($searchQuery),
            'center' => [
                'lat' => $lat,
                'lng' => $lng,
            ],
            'radius_km' => $radiusKm,
            'osm_unavailable' => $osmUnavailable,
        ];

        return UnifiedLocationResource::collection($paginated)
            ->additional(['meta' => $meta]);
    }

    /**
     * Calculate distance between two points using Haversine formula.
     *
     * @param  float  $lat1  Latitude of first point
     * @param  float  $lng1  Longitude of first point
     * @param  float  $lat2  Latitude of second point
     * @param  float  $lng2  Longitude of second point
     * @return float Distance in meters
     */
    private function calculateHaversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // Earth's radius in meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
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
