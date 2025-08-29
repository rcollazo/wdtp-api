<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LocationIndexRequest;
use App\Http\Resources\LocationResource;
use App\Http\Resources\WageStatisticsResource;
use App\Models\Location;
use App\Services\WageStatisticsService;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Tag(
 *     name="Locations",
 *     description="Location management and wage statistics"
 * )
 */
class LocationController extends Controller
{
    public function __construct(private WageStatisticsService $statisticsService) {}

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
