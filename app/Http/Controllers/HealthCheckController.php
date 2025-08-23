<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthCheckController extends Controller
{
    public function basic(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function deep(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            return response()->json([
                'database' => 'connected',
                'postgis' => DB::select('SELECT PostGIS_Version() as version')[0]->version
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}