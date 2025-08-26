<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWageReportRequest;
use App\Http\Resources\WageReportListItemResource;
use App\Http\Resources\WageReportResource;
use App\Http\Resources\WageStatisticsResource;
use App\Models\WageReport;
use App\Services\WageStatisticsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * @OA\Tag(
 *     name="Wage Reports",
 *     description="Anonymous wage report submissions and search"
 * )
 */
class WageReportController extends Controller
{
    public function __construct(private WageStatisticsService $statisticsService) {}

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
     * @OA\Get(
     *     path="/api/v1/wage-reports/stats",
     *     summary="Get global wage statistics",
     *     description="Retrieve comprehensive wage statistics with optional filtering",
     *     tags={"Wage Reports"},
     *
     *     @OA\Parameter(
     *         name="date_from",
     *         in="query",
     *         description="Filter by effective date from (YYYY-MM-DD)",
     *         required=false,
     *
     *         @OA\Schema(type="string", format="date", example="2024-01-01")
     *     ),
     *
     *     @OA\Parameter(
     *         name="date_to",
     *         in="query",
     *         description="Filter by effective date to (YYYY-MM-DD)",
     *         required=false,
     *
     *         @OA\Schema(type="string", format="date", example="2024-12-31")
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
     *         name="position_category_id",
     *         in="query",
     *         description="Filter by position category ID",
     *         required=false,
     *
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *
     *     @OA\Parameter(
     *         name="min_wage",
     *         in="query",
     *         description="Minimum hourly wage in dollars",
     *         required=false,
     *
     *         @OA\Schema(type="number", format="float", example=15.00)
     *     ),
     *
     *     @OA\Parameter(
     *         name="max_wage",
     *         in="query",
     *         description="Maximum hourly wage in dollars",
     *         required=false,
     *
     *         @OA\Schema(type="number", format="float", example=50.00)
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
     *         name="unionized",
     *         in="query",
     *         description="Filter by unionization status",
     *         required=false,
     *
     *         @OA\Schema(type="boolean", example=true)
     *     ),
     *
     *     @OA\Parameter(
     *         name="tips_included",
     *         in="query",
     *         description="Filter by whether tips are included",
     *         required=false,
     *
     *         @OA\Schema(type="boolean", example=false)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Global wage statistics including percentiles and breakdowns",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="data", ref="#/components/schemas/WageStatistics")
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function stats(Request $request): JsonResource
    {
        // Validate query parameters
        $request->validate([
            'date_from' => 'sometimes|date|before_or_equal:date_to',
            'date_to' => 'sometimes|date|after_or_equal:date_from',
            'employment_type' => 'sometimes|in:full_time,part_time,seasonal,contract',
            'position_category_id' => 'sometimes|integer|exists:position_categories,id',
            'min_wage' => 'sometimes|numeric|min:0',
            'max_wage' => 'sometimes|numeric|min:0|gt:min_wage',
            'currency' => 'sometimes|string|size:3',
            'unionized' => 'sometimes|boolean',
            'tips_included' => 'sometimes|boolean',
        ]);

        // Build filters array
        $filters = [];

        if ($request->filled('date_from')) {
            $filters['date_from'] = $request->date_from;
        }

        if ($request->filled('date_to')) {
            $filters['date_to'] = $request->date_to;
        }

        if ($request->filled('employment_type')) {
            $filters['employment_type'] = $request->employment_type;
        }

        if ($request->filled('position_category_id')) {
            $filters['position_category_id'] = $request->position_category_id;
        }

        if ($request->filled('min_wage')) {
            $filters['min_wage_cents'] = (int) ($request->min_wage * 100);
        }

        if ($request->filled('max_wage')) {
            $filters['max_wage_cents'] = (int) ($request->max_wage * 100);
        }

        if ($request->filled('currency')) {
            $filters['currency'] = strtoupper($request->currency);
        }

        if ($request->has('unionized')) {
            $filters['unionized'] = $request->boolean('unionized');
        }

        if ($request->has('tips_included')) {
            $filters['tips_included'] = $request->boolean('tips_included');
        }

        $statistics = $this->statisticsService->getGlobalStatistics($filters);

        return new WageStatisticsResource($statistics);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/wage-reports",
     *     summary="Submit a new wage report",
     *     tags={"Wage Reports"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         description="Wage report submission data",
     *
     *         @OA\JsonContent(
     *             required={"location_id", "position_category_id", "wage_amount", "wage_type", "employment_type"},
     *
     *             @OA\Property(
     *                 property="location_id",
     *                 type="integer",
     *                 description="ID of the location where work is performed",
     *                 example=1
     *             ),
     *             @OA\Property(
     *                 property="position_category_id",
     *                 type="integer",
     *                 description="ID of the position category/job type",
     *                 example=1
     *             ),
     *             @OA\Property(
     *                 property="wage_amount",
     *                 type="number",
     *                 format="float",
     *                 description="Wage amount in dollars",
     *                 example=15.50,
     *                 minimum=1,
     *                 maximum=999999.99
     *             ),
     *             @OA\Property(
     *                 property="wage_type",
     *                 type="string",
     *                 description="Wage payment period",
     *                 enum={"hourly", "weekly", "biweekly", "monthly", "yearly", "per_shift"},
     *                 example="hourly"
     *             ),
     *             @OA\Property(
     *                 property="employment_type",
     *                 type="string",
     *                 description="Type of employment arrangement",
     *                 enum={"full_time", "part_time", "contract", "seasonal"},
     *                 example="part_time"
     *             ),
     *             @OA\Property(
     *                 property="years_experience",
     *                 type="integer",
     *                 description="Years of experience in this role (optional)",
     *                 example=2,
     *                 minimum=0,
     *                 maximum=50,
     *                 nullable=true
     *             ),
     *             @OA\Property(
     *                 property="hours_per_week",
     *                 type="integer",
     *                 description="Average hours worked per week (optional)",
     *                 example=30,
     *                 minimum=1,
     *                 maximum=168,
     *                 nullable=true
     *             ),
     *             @OA\Property(
     *                 property="effective_date",
     *                 type="string",
     *                 format="date",
     *                 description="Date when this wage became effective (optional, defaults to today)",
     *                 example="2024-08-01",
     *                 nullable=true
     *             ),
     *             @OA\Property(
     *                 property="tips_included",
     *                 type="boolean",
     *                 description="Whether tips are included in the wage amount (optional)",
     *                 example=false,
     *                 nullable=true
     *             ),
     *             @OA\Property(
     *                 property="unionized",
     *                 type="boolean",
     *                 description="Whether the position is unionized (optional)",
     *                 example=true,
     *                 nullable=true
     *             ),
     *             @OA\Property(
     *                 property="additional_notes",
     *                 type="string",
     *                 description="Additional notes or context about the wage (optional)",
     *                 example="Includes health benefits and 401k matching",
     *                 maxLength=1000,
     *                 nullable=true
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Wage report created successfully",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="data",
     *                 ref="#/components/schemas/WageReport"
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Wage report submitted successfully"
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="The given data was invalid."
     *             ),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 @OA\Property(
     *                     property="wage_amount",
     *                     type="array",
     *
     *                     @OA\Items(
     *                         type="string",
     *                         example="The wage amount is required."
     *                     )
     *                 ),
     *
     *                 @OA\Property(
     *                     property="location_id",
     *                     type="array",
     *
     *                     @OA\Items(
     *                         type="string",
     *                         example="The selected location does not exist."
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Server error during submission"
     *     )
     * )
     */
    public function store(StoreWageReportRequest $request)
    {
        try {
            // Get formatted data from the form request
            $wageReportData = $request->getWageReportData();

            // Create the wage report - the observer will handle:
            // - Deriving organization_id from location
            // - Normalizing wage to hourly cents
            // - Calculating sanity score
            // - Setting status (approved/pending)
            // - Awarding XP points to authenticated users
            // - Updating counters for location/organization
            $wageReport = WageReport::create($wageReportData);

            // Load relationships for response
            $wageReport->load(['location', 'organization', 'positionCategory']);

            // Return success response with created resource
            return (new WageReportResource($wageReport))
                ->additional([
                    'message' => 'Wage report submitted successfully',
                ])
                ->response()
                ->setStatusCode(Response::HTTP_CREATED);

        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('Error creating wage report', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
                'request_data' => $request->validated(),
            ]);

            // Return generic error response
            return response()->json([
                'message' => 'An error occurred while submitting your wage report. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
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
            // The nearby scope already orders by distance, so we don't need to apply additional sorting
            // The query is already ordered by ST_Distance() in the nearby scope
        } else {
            // Fallback to recent if no coordinates provided
            $query->latest('created_at');
        }
    }
}
