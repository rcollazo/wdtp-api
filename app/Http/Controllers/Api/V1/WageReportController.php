<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WageReportListItemResource;
use App\Http\Resources\WageReportResource;
use App\Models\WageReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Tag(
 *     name="Wage Reports",
 *     description="Anonymous wage report submissions and search"
 * )
 */
class WageReportController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/wage-reports",
     *     summary="List wage reports with filtering and pagination",
     *     tags={"Wage Reports"},
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
     *         name="organization_slug",
     *         in="query",
     *         description="Filter by organization slug",
     *         required=false,
     *
     *         @OA\Schema(type="string", example="starbucks")
     *     ),
     *
     *     @OA\Parameter(
     *         name="location_id",
     *         in="query",
     *         description="Filter by location ID",
     *         required=false,
     *
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *
     *     @OA\Parameter(
     *         name="job_title",
     *         in="query",
     *         description="ILIKE search on job title",
     *         required=false,
     *
     *         @OA\Schema(type="string", example="Barista")
     *     ),
     *
     *     @OA\Parameter(
     *         name="min_hr",
     *         in="query",
     *         description="Minimum hourly wage in dollars",
     *         required=false,
     *
     *         @OA\Schema(type="number", format="float", example=15.00)
     *     ),
     *
     *     @OA\Parameter(
     *         name="max_hr",
     *         in="query",
     *         description="Maximum hourly wage in dollars",
     *         required=false,
     *
     *         @OA\Schema(type="number", format="float", example=25.00)
     *     ),
     *
     *     @OA\Parameter(
     *         name="since",
     *         in="query",
     *         description="Effective date filter (YYYY-MM-DD)",
     *         required=false,
     *
     *         @OA\Schema(type="string", format="date", example="2024-01-01")
     *     ),
     *
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by status",
     *         required=false,
     *
     *         @OA\Schema(type="string", enum={"approved", "pending", "rejected"}, default="approved")
     *     ),
     *
     *     @OA\Parameter(
     *         name="employment_type",
     *         in="query",
     *         description="Filter by employment type",
     *         required=false,
     *
     *         @OA\Schema(type="string", enum={"full_time", "part_time", "seasonal", "contract"})
     *     ),
     *
     *     @OA\Parameter(
     *         name="currency",
     *         in="query",
     *         description="Currency code filter",
     *         required=false,
     *
     *         @OA\Schema(type="string", example="USD", default="USD")
     *     ),
     *
     *     @OA\Parameter(
     *         name="near",
     *         in="query",
     *         description="Latitude,longitude for spatial search",
     *         required=false,
     *
     *         @OA\Schema(type="string", example="40.7128,-74.0060")
     *     ),
     *
     *     @OA\Parameter(
     *         name="radius_km",
     *         in="query",
     *         description="Search radius in kilometers (requires near parameter)",
     *         required=false,
     *
     *         @OA\Schema(type="number", format="float", default=10.0, maximum=100.0)
     *     ),
     *
     *     @OA\Parameter(
     *         name="sort",
     *         in="query",
     *         description="Sort order",
     *         required=false,
     *
     *         @OA\Schema(type="string", enum={"recent", "oldest", "highest", "lowest", "closest"}, default="recent")
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
     *         description="Paginated list of wage reports",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *
     *                 @OA\Items(ref="#/components/schemas/WageReport")
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
        // Validate query parameters
        $request->validate([
            'organization_id' => 'sometimes|integer|exists:organizations,id',
            'organization_slug' => 'sometimes|string|exists:organizations,slug',
            'location_id' => 'sometimes|integer|exists:locations,id',
            'job_title' => 'sometimes|string|min:2',
            'min_hr' => 'sometimes|numeric|min:0',
            'max_hr' => 'sometimes|numeric|min:0|gt:min_hr',
            'since' => 'sometimes|date',
            'status' => 'sometimes|in:approved,pending,rejected',
            'employment_type' => 'sometimes|in:full_time,part_time,seasonal,contract',
            'currency' => 'sometimes|string|size:3',
            'near' => ['sometimes', 'regex:/^-?\d+\.?\d*,-?\d+\.?\d*$/', function ($attribute, $value, $fail) {
                if ($value) {
                    [$lat, $lng] = explode(',', $value);
                    $lat = (float) $lat;
                    $lng = (float) $lng;
                    
                    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                        $fail('Latitude must be between -90 and 90, longitude between -180 and 180');
                    }
                }
            }],
            'radius_km' => 'sometimes|numeric|min:0.1|max:100',
            'sort' => 'sometimes|in:recent,oldest,highest,lowest,closest',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        // Extract and validate spatial parameters
        $near = $request->get('near');
        $lat = null;
        $lng = null;
        $radiusKm = (float) $request->get('radius_km', 10.0);

        if ($near) {
            [$lat, $lng] = explode(',', $near);
            $lat = (float) $lat;
            $lng = (float) $lng;
        }

        $status = $request->get('status', 'approved');
        $sort = $request->get('sort', 'recent');
        $perPage = min((int) $request->get('per_page', 25), 100);

        // Build base query
        $query = WageReport::query()
            ->with(['location', 'organization']);

        // Apply status filter (default to approved)
        match ($status) {
            'approved' => $query->approved(),
            'pending' => $query->pending(),
            'rejected' => $query->rejected(),
            default => $query->approved(),
        };

        // Apply filters
        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }

        if ($request->filled('organization_slug')) {
            $query->forOrganization($request->organization_slug);
        }

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        if ($request->filled('job_title')) {
            $query->byJobTitle($request->job_title);
        }

        if ($request->filled('min_hr') || $request->filled('max_hr')) {
            $minCents = $request->filled('min_hr') ? (int) ($request->min_hr * 100) : 0;
            $maxCents = $request->filled('max_hr') ? (int) ($request->max_hr * 100) : 999999;
            $query->range($minCents, $maxCents);
        }

        if ($request->filled('since')) {
            $query->since($request->since);
        }

        if ($request->filled('employment_type')) {
            $query->byEmploymentType($request->employment_type);
        }

        if ($request->filled('currency')) {
            $query->inCurrency($request->currency);
        }

        // Apply spatial filtering if near parameter provided
        if ($near) {
            $radiusMeters = $radiusKm * 1000;
            $query->nearby($lat, $lng, (int) $radiusMeters);
        }

        // Apply sorting
        $this->applySorting($query, $sort, $lat, $lng);

        $wageReports = $query->paginate($perPage);

        return WageReportListItemResource::collection($wageReports);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/wage-reports/{wageReportId}",
     *     summary="Show single wage report",
     *     tags={"Wage Reports"},
     *
     *     @OA\Parameter(
     *         name="wageReportId",
     *         in="path",
     *         description="Wage report ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Wage report details",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="data", ref="#/components/schemas/WageReport")
     *         )
     *     ),
     *
     *     @OA\Response(response=404, description="Wage report not found")
     * )
     */
    public function show(int $wageReportId): JsonResource
    {
        // Only show approved wage reports to public
        $wageReport = WageReport::query()
            ->approved()
            ->with(['location', 'organization'])
            ->findOrFail($wageReportId);

        return new WageReportResource($wageReport);
    }

    /**
     * Apply sorting to the query based on the sort parameter.
     */
    private function applySorting($query, string $sort, ?float $lat = null, ?float $lng = null): void
    {
        match ($sort) {
            'recent' => $query->latest('created_at'),
            'oldest' => $query->oldest('created_at'),
            'highest' => $query->orderByDesc('normalized_hourly_cents'),
            'lowest' => $query->orderBy('normalized_hourly_cents'),
            'closest' => $this->applySpatialSort($query, $lat, $lng),
            default => $query->latest('created_at'),
        };
    }

    /**
     * Apply spatial sorting if coordinates are provided.
     */
    private function applySpatialSort($query, ?float $lat, ?float $lng): void
    {
        if ($lat !== null && $lng !== null) {
            // The nearby scope already orders by distance, but if we need to reorder
            $query->orderByDistance($lat, $lng);
        } else {
            // Fallback to recent if no coordinates provided
            $query->latest('created_at');
        }
    }
}
