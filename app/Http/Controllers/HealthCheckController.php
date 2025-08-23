<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Annotations as OA;

class HealthCheckController extends Controller
{
    /**
     * Basic health check endpoint.
     *
     * @OA\Get(
     *     path="/api/v1/healthz",
     *     tags={"Health"},
     *     summary="Basic health check",
     *     description="Returns basic API status",
     *
     *     @OA\Response(
     *         response=200,
     *         description="API is healthy",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="string", example="ok")
     *         )
     *     )
     * )
     */
    public function basic(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Deep health check endpoint.
     *
     * @OA\Get(
     *     path="/api/v1/healthz/deep",
     *     tags={"Health"},
     *     summary="Deep health check with database connectivity",
     *     description="Returns API status including database and PostGIS connectivity",
     *
     *     @OA\Response(
     *         response=200,
     *         description="API and dependencies are healthy",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="database", type="string", example="connected"),
     *             @OA\Property(property="postgis", type="string", example="3.5 USE_GEOS=1 USE_PROJ=1 USE_STATS=1")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Health check failed",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="error", type="string", example="Database connection failed")
     *         )
     *     )
     * )
     */
    public function deep(): JsonResponse
    {
        try {
            DB::connection()->getPdo();

            return response()->json([
                'database' => 'connected',
                'postgis' => DB::select('SELECT PostGIS_Version() as version')[0]->version,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
