<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
