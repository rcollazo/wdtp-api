# WDTP Wage Reports v1 Implementation Plan

## Plan Metadata
- **Version**: 1.0
- **Created**: 2025-01-25
- **Status**: Ready for Implementation
- **Laravel Version**: 12
- **PHP Version**: 8.3+
- **Database**: PostgreSQL 17 + PostGIS 3.5
- **Testing Framework**: PHPUnit

## Quick Reference

### Key Routes
```
POST   /api/v1/wage-reports           # Anonymous submission
GET    /api/v1/wage-reports           # List approved reports (spatial + filters)
GET    /api/v1/wage-reports/{id}      # Show individual report
POST   /api/v1/wage-reports/{id}/vote # Vote helpful/not helpful (auth)
POST   /api/v1/wage-reports/{id}/flag # Flag inappropriate content (auth)
PATCH  /api/v1/wage-reports/{id}/approve  # Approve report (moderator+)
PATCH  /api/v1/wage-reports/{id}/reject   # Reject report (moderator+)

GET    /api/v1/locations/{id}/wage-reports    # Reports for location
GET    /api/v1/organizations/{id}/wage-reports # Reports for organization
```

### Cache Keys
- `wage_reports_list_{hash}` - List endpoint results (5 min)
- `wage_reports_stats_{location_id}` - Location statistics (15 min)
- `recent_wage_reports` - Homepage recent reports (2 min)

### Status Workflow
```
pending → approved/rejected/flagged
         ↓
    (moderator action)
```

## Complete Implementation Plan

### A. Foundation Layer (Data & Models)

#### DEV-A1: Database Migration & Schema
**Commit**: `feat(wage-reports): create comprehensive database schema with PostGIS support`

**Migration**: `create_wage_reports_table.php`
```php
// Core fields
$table->id();
$table->foreignId('location_id')->constrained()->cascadeOnDelete();
$table->foreignId('position_category_id')->constrained();
$table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
$table->decimal('wage_amount', 8, 2); // $999,999.99 max
$table->enum('wage_type', ['hourly', 'salary', 'commission'])->default('hourly');
$table->enum('employment_type', ['full_time', 'part_time', 'contract', 'temporary']);

// Status and moderation
$table->enum('status', ['pending', 'approved', 'rejected', 'flagged'])->default('pending');
$table->timestamp('approved_at')->nullable();
$table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
$table->text('review_notes')->nullable();

// Engagement metrics
$table->integer('helpful_votes')->default(0);
$table->integer('not_helpful_votes')->default(0);
$table->integer('flag_count')->default(0);

// Optional context
$table->integer('years_experience')->nullable();
$table->text('additional_notes')->nullable();

// Audit fields
$table->timestamps();
$table->softDeletes();

// Indexes for performance
$table->index(['status', 'created_at']);
$table->index(['location_id', 'status']);
$table->index(['position_category_id', 'status']);
$table->index(['user_id', 'created_at']);
$table->index(['wage_amount', 'status']);
```

**Tests Required**:
- Migration runs without errors
- All indexes created properly
- Foreign key constraints work
- Enum values enforce correctly
- Soft deletes function

#### DEV-A2: WageReport Model with Relationships
**Commit**: `feat(wage-reports): implement WageReport model with comprehensive relationships and scopes`

**Model Features**:
```php
class WageReport extends Model
{
    use HasFactory, SoftDeletes;
    
    // Relationships
    public function location(): BelongsTo;
    public function positionCategory(): BelongsTo;
    public function user(): BelongsTo;
    public function approver(): BelongsTo; // users table
    public function votes(): HasMany; // WageReportVote model
    public function flags(): HasMany; // WageReportFlag model
    
    // Scopes
    public function scopeApproved($query): Builder;
    public function scopePending($query): Builder;
    public function scopeForLocation($query, $locationId): Builder;
    public function scopeForPosition($query, $positionId): Builder;
    public function scopeRecent($query, $days = 30): Builder;
    public function scopeByWageRange($query, $min, $max): Builder;
    
    // Computed properties
    public function getHelpfulnessRatioAttribute(): float;
    public function getIsAnonymousAttribute(): bool;
    public function getCanBeModeratedAttribute(): bool;
    
    // Business logic methods
    public function approve(User $moderator, ?string $notes = null): bool;
    public function reject(User $moderator, string $reason): bool;
    public function flag(User $user, string $reason): bool;
    public function vote(User $user, bool $isHelpful): bool;
}
```

**Tests Required**:
- All relationships load correctly
- Scopes filter properly
- Status transitions work
- Computed properties calculate correctly
- Business logic methods enforce rules

#### DEV-A3: Supporting Models (Votes & Flags)
**Commit**: `feat(wage-reports): add voting and flagging support models with constraints`

**WageReportVote Model**:
```php
// Migration
$table->id();
$table->foreignId('wage_report_id')->constrained()->cascadeOnDelete();
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
$table->boolean('is_helpful');
$table->timestamps();
$table->unique(['wage_report_id', 'user_id']); // One vote per user per report

// Model
class WageReportVote extends Model
{
    public function wageReport(): BelongsTo;
    public function user(): BelongsTo;
}
```

**WageReportFlag Model**:
```php
// Migration  
$table->id();
$table->foreignId('wage_report_id')->constrained()->cascadeOnDelete();
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
$table->enum('reason', ['inappropriate', 'spam', 'duplicate', 'fake', 'other']);
$table->text('details')->nullable();
$table->enum('status', ['pending', 'reviewed', 'dismissed'])->default('pending');
$table->timestamps();
$table->index(['wage_report_id', 'status']);

// Model
class WageReportFlag extends Model
{
    public function wageReport(): BelongsTo;
    public function user(): BelongsTo;
}
```

**Tests Required**:
- Unique constraints prevent duplicate votes
- Flag reasons validate properly
- Cascading deletes work correctly
- Vote counting updates parent model

### B. Data Layer (Factory & Seeder)

#### DEV-B1: Comprehensive Factory
**Commit**: `feat(wage-reports): create comprehensive factory with realistic wage data and states`

**Factory States**:
```php
class WageReportFactory extends Factory
{
    // Base realistic wage data
    public function definition(): array;
    
    // Status states
    public function pending(): static;
    public function approved(): static;
    public function rejected(): static;
    public function flagged(): static;
    
    // Employment states
    public function fullTime(): static;
    public function partTime(): static;
    public function contract(): static;
    
    // Industry-specific wage ranges
    public function foodService(): static; // $12-25/hr
    public function retail(): static; // $10-22/hr
    public function healthcare(): static; // $15-45/hr
    public function tech(): static; // $25-80/hr
    
    // Geographic wage adjustments
    public function highCostArea(): static; // +30% wage
    public function lowCostArea(): static; // -20% wage
    
    // Experience levels
    public function entryLevel(): static; // 0-2 years
    public function experienced(): static; // 3-7 years
    public function senior(): static; // 8+ years
    
    // Engagement states
    public function withVotes(int $helpful = 5, int $notHelpful = 1): static;
    public function controversial(): static; // Equal helpful/not helpful
    public function flaggedMultiple(): static; // Multiple flags
}
```

**Tests Required**:
- All states generate valid data
- Industry wage ranges are realistic
- Geographic adjustments work
- Experience correlates with wage ranges
- Relationships generate properly

#### DEV-B2: Database Seeder
**Commit**: `feat(wage-reports): add comprehensive seeder with real-world wage distribution`

**Seeder Features**:
- Generate 1000+ wage reports across all US locations
- Realistic wage distributions by industry/location
- Proper status distribution (85% approved, 10% pending, 5% other)
- Vote patterns that reflect real user behavior
- Flag patterns for testing moderation workflows

**Tests Required**:
- Seeder creates expected number of records
- Wage distributions are realistic
- Status ratios match expectations
- All relationships are properly linked

### C. API Layer (Controllers & Resources)

#### DEV-C1: API Resource Classes
**Commit**: `feat(wage-reports): create comprehensive API resources with privacy controls`

**WageReportResource**:
```php
class WageReportResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'wage_amount' => $this->wage_amount,
            'wage_type' => $this->wage_type,
            'employment_type' => $this->employment_type,
            'years_experience' => $this->years_experience,
            'additional_notes' => $this->additional_notes,
            
            // Conditional fields based on auth
            'is_anonymous' => $this->when($this->user_id === null, true),
            'submitted_by' => $this->when(
                $request->user()?->can('viewSubmitter', $this->resource),
                new UserResource($this->user)
            ),
            
            // Engagement metrics
            'helpful_votes' => $this->helpful_votes,
            'not_helpful_votes' => $this->not_helpful_votes,
            'helpfulness_ratio' => $this->helpfulness_ratio,
            
            // User's interaction with this report
            'user_vote' => $this->when(auth()->check(), function() use ($request) {
                return $this->votes()->where('user_id', $request->user()->id)->first()?->is_helpful;
            }),
            'user_flagged' => $this->when(auth()->check(), function() use ($request) {
                return $this->flags()->where('user_id', $request->user()->id)->exists();
            }),
            
            // Relationships
            'location' => new LocationResource($this->whenLoaded('location')),
            'position_category' => new PositionCategoryResource($this->whenLoaded('positionCategory')),
            
            // Timestamps
            'created_at' => $this->created_at,
            'approved_at' => $this->approved_at,
        ];
    }
}
```

**WageReportCollection** for list endpoints with meta data and statistics.

**Tests Required**:
- Privacy controls work correctly
- Conditional fields show/hide properly
- User-specific data loads correctly
- Resource relationships load efficiently

#### DEV-C2: Form Request Validation
**Commit**: `feat(wage-reports): implement comprehensive form request validation with business rules`

**StoreWageReportRequest**:
```php
class StoreWageReportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'exists:locations,id'],
            'position_category_id' => ['required', 'exists:position_categories,id'],
            'wage_amount' => ['required', 'numeric', 'min:1', 'max:999999.99'],
            'wage_type' => ['required', 'in:hourly,salary,commission'],
            'employment_type' => ['required', 'in:full_time,part_time,contract,temporary'],
            'years_experience' => ['nullable', 'integer', 'min:0', 'max:50'],
            'additional_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
    
    // Custom validation for duplicate prevention
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->user() && $this->isDuplicateSubmission()) {
                $validator->errors()->add('duplicate', 
                    'You have already submitted a wage report for this position at this location within the last 30 days.');
            }
        });
    }
    
    private function isDuplicateSubmission(): bool;
}
```

**Tests Required**:
- All validation rules work correctly
- Duplicate prevention functions
- Error messages are user-friendly
- Authorization checks pass/fail appropriately

#### DEV-C3: Controller Implementation
**Commit**: `feat(wage-reports): implement full CRUD controller with spatial search and moderation`

**WageReportController** methods:
- `index()` - List with spatial search, filters, pagination
- `store()` - Create new report (public access)
- `show()` - View individual report
- `vote()` - Vote on report helpfulness (auth required)
- `flag()` - Flag inappropriate content (auth required)
- `approve()` - Approve report (moderator+)
- `reject()` - Reject report (moderator+)

**Spatial Search Features**:
```php
public function index(Request $request): JsonResource
{
    $query = WageReport::query()
        ->with(['location', 'positionCategory'])
        ->approved();
    
    // Spatial filtering
    if ($request->has('near')) {
        [$lat, $lon] = explode(',', $request->get('near'));
        $radius = $request->get('radius_km', 10);
        
        $query->whereHas('location', function($q) use ($lat, $lon, $radius) {
            $q->near($lat, $lon, $radius);
        });
    }
    
    // Filters
    if ($request->has('position_category_id')) {
        $query->where('position_category_id', $request->get('position_category_id'));
    }
    
    if ($request->has('wage_min') || $request->has('wage_max')) {
        $query->byWageRange(
            $request->get('wage_min', 0),
            $request->get('wage_max', 999999)
        );
    }
    
    // Sorting
    $sortBy = $request->get('sort', 'created_at');
    $sortDir = $request->get('direction', 'desc');
    $query->orderBy($sortBy, $sortDir);
    
    return WageReportResource::collection($query->paginate(15));
}
```

**Tests Required**:
- All endpoints work with proper auth
- Spatial search returns correct results
- Filtering works accurately
- Moderation actions enforce permissions
- Error handling works correctly

### D. Integration Layer (Related Models)

#### DEV-D1: Location Integration
**Commit**: `feat(locations): add wage reports relationship and statistics methods`

**Location Model Updates**:
```php
// New relationship
public function wageReports(): HasMany
{
    return $this->hasMany(WageReport::class);
}

// Statistics methods
public function getWageStatsAttribute(): array
{
    return Cache::remember("wage_stats_{$this->id}", 900, function() {
        $approved = $this->wageReports()->approved();
        
        return [
            'total_reports' => $approved->count(),
            'avg_wage' => round($approved->avg('wage_amount'), 2),
            'min_wage' => $approved->min('wage_amount'),
            'max_wage' => $approved->max('wage_amount'),
            'latest_report' => $approved->latest()->first()?->created_at,
        ];
    });
}
```

**LocationController Updates**:
```php
public function wageReports(Location $location, Request $request): JsonResource
{
    $query = $location->wageReports()
        ->with(['positionCategory'])
        ->approved();
    
    // Apply filters...
    
    return WageReportResource::collection($query->paginate(10));
}
```

**Tests Required**:
- Wage reports relationship works
- Statistics calculate correctly
- Caching functions properly
- Location-specific wage reports endpoint works

#### DEV-D2: Organization Integration  
**Commit**: `feat(organizations): add wage reports aggregation across all locations`

**Organization Model Updates**:
```php
public function wageReports(): HasManyThrough
{
    return $this->hasManyThrough(WageReport::class, Location::class);
}

public function getWageStatsAttribute(): array
{
    return Cache::remember("org_wage_stats_{$this->id}", 900, function() {
        $approved = $this->wageReports()->approved();
        
        return [
            'total_reports' => $approved->count(),
            'locations_with_reports' => $this->locations()
                ->whereHas('wageReports', function($q) {
                    $q->approved();
                })->count(),
            'avg_wage' => round($approved->avg('wage_amount'), 2),
            'position_breakdown' => $approved
                ->with('positionCategory')
                ->get()
                ->groupBy('positionCategory.name')
                ->map(function($reports) {
                    return [
                        'count' => $reports->count(),
                        'avg_wage' => round($reports->avg('wage_amount'), 2),
                    ];
                }),
        ];
    });
}
```

**Tests Required**:
- HasManyThrough relationship works
- Organization statistics aggregate correctly
- Position breakdown calculates properly
- Caching works across organization hierarchy

### E. Business Logic Layer

#### DEV-E1: Moderation Service
**Commit**: `feat(wage-reports): implement comprehensive moderation service with audit logging`

**WageReportModerationService**:
```php
class WageReportModerationService
{
    public function approve(WageReport $report, User $moderator, ?string $notes = null): bool
    {
        DB::transaction(function() use ($report, $moderator, $notes) {
            $report->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $moderator->id,
                'review_notes' => $notes,
            ]);
            
            // Award experience points to submitter
            if ($report->user) {
                $report->user->addExperience(10, 'wage_report_approved');
            }
            
            // Clear caches
            $this->clearRelatedCaches($report);
            
            // Log action
            activity('wage_report_moderation')
                ->performedOn($report)
                ->causedBy($moderator)
                ->withProperties(['action' => 'approved', 'notes' => $notes])
                ->log('Wage report approved');
        });
        
        return true;
    }
    
    public function reject(WageReport $report, User $moderator, string $reason): bool;
    public function flag(WageReport $report, User $flagger, string $reason, ?string $details = null): bool;
    
    private function clearRelatedCaches(WageReport $report): void
    {
        Cache::forget("wage_stats_{$report->location_id}");
        Cache::forget("org_wage_stats_{$report->location->organization_id}");
        Cache::forget('recent_wage_reports');
        // Clear list caches with pattern
    }
}
```

**Tests Required**:
- Moderation actions work correctly
- Audit logging captures all actions
- Cache clearing works
- Experience points are awarded
- Transaction rollback works on failures

#### DEV-E2: Duplicate Detection Service
**Commit**: `feat(wage-reports): implement intelligent duplicate detection with configurable rules`

**DuplicateDetectionService**:
```php
class DuplicateDetectionService  
{
    public function isDuplicate(array $reportData, ?User $user = null): bool
    {
        if (!$user) {
            return false; // Anonymous submissions can't be duplicates
        }
        
        return WageReport::where('user_id', $user->id)
            ->where('location_id', $reportData['location_id'])
            ->where('position_category_id', $reportData['position_category_id'])
            ->where('created_at', '>=', now()->subDays(30))
            ->exists();
    }
    
    public function findSimilar(WageReport $report, int $limit = 5): Collection
    {
        return WageReport::where('location_id', $report->location_id)
            ->where('position_category_id', $report->position_category_id)
            ->where('id', '!=', $report->id)
            ->approved()
            ->whereBetween('wage_amount', [
                $report->wage_amount * 0.8,  // ±20% wage range
                $report->wage_amount * 1.2
            ])
            ->orderByRaw('ABS(wage_amount - ?)', [$report->wage_amount])
            ->limit($limit)
            ->get();
    }
}
```

**Tests Required**:
- Duplicate detection works correctly
- Time window enforcement works  
- Similar report finding works
- Anonymous submissions handled properly

### F. API Routing & Middleware

#### DEV-F1: Route Configuration
**Commit**: `feat(wage-reports): configure comprehensive API routes with proper middleware`

**routes/api.php**:
```php
// Wage Reports routes
Route::prefix('wage-reports')->group(function () {
    // Public routes
    Route::get('/', [WageReportController::class, 'index']); // List approved
    Route::post('/', [WageReportController::class, 'store']); // Anonymous submission
    Route::get('/{wageReport}', [WageReportController::class, 'show']);
    
    // Authenticated routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/{wageReport}/vote', [WageReportController::class, 'vote']);
        Route::post('/{wageReport}/flag', [WageReportController::class, 'flag']);
        
        // Moderator routes
        Route::middleware('role:moderator,admin')->group(function () {
            Route::patch('/{wageReport}/approve', [WageReportController::class, 'approve']);
            Route::patch('/{wageReport}/reject', [WageReportController::class, 'reject']);
        });
    });
});

// Related model routes  
Route::get('locations/{location}/wage-reports', [LocationController::class, 'wageReports']);
Route::get('organizations/{organization}/wage-reports', [OrganizationController::class, 'wageReports']);
```

**Tests Required**:
- All routes resolve correctly
- Middleware enforcement works
- Route model binding works
- Nested resource routes work

#### DEV-F2: Rate Limiting & Caching
**Commit**: `feat(wage-reports): implement intelligent caching and rate limiting strategies`

**Caching Strategy**:
```php
// bootstrap/app.php middleware configuration
->withMiddleware(function (Middleware $middleware) {
    $middleware->throttle([
        'wage-reports-submit' => [10, 60], // 10 submissions per hour
        'wage-reports-vote' => [30, 60],   // 30 votes per hour  
        'wage-reports-flag' => [5, 60],    // 5 flags per hour
    ]);
})

// Controller caching
public function index(Request $request): JsonResource
{
    $cacheKey = 'wage_reports_list_' . md5($request->getQueryString());
    
    return Cache::remember($cacheKey, 300, function() use ($request) {
        // Expensive query logic
    });
}
```

**Tests Required**:
- Rate limiting works correctly
- Cache keys generate properly
- Cache invalidation works
- Cache hit/miss scenarios work

### G. Testing Suite

#### TEST-G1: Model Testing
**Commit**: `test(wage-reports): add comprehensive model testing with relationships and scopes`

**Test Coverage**:
- WageReportTest: All model methods, scopes, relationships
- WageReportVoteTest: Voting logic and constraints  
- WageReportFlagTest: Flagging functionality
- Factory tests: All states generate valid data
- Relationship tests: All associations load correctly

**Key Test Cases**:
```php
/** @test */
public function it_calculates_helpfulness_ratio_correctly()
{
    $report = WageReport::factory()->create();
    
    // Create votes
    WageReportVote::factory(8)->helpful()->create(['wage_report_id' => $report->id]);
    WageReportVote::factory(2)->notHelpful()->create(['wage_report_id' => $report->id]);
    
    $report->refresh();
    
    $this->assertEquals(0.8, $report->helpfulness_ratio);
}

/** @test */
public function it_prevents_duplicate_submissions_within_30_days()
{
    $user = User::factory()->create();
    $location = Location::factory()->create();
    $position = PositionCategory::factory()->create();
    
    // First submission
    WageReport::factory()->create([
        'user_id' => $user->id,
        'location_id' => $location->id,
        'position_category_id' => $position->id,
    ]);
    
    // Second submission should be blocked
    $this->assertFalse(
        app(DuplicateDetectionService::class)->isDuplicate([
            'location_id' => $location->id,
            'position_category_id' => $position->id,
        ], $user)
    );
}
```

#### TEST-G2: API Testing with PostGIS
**Commit**: `test(wage-reports): add comprehensive API testing with spatial queries and authentication`

**Spatial Query Tests**:
```php
/** @test */
public function it_returns_wage_reports_within_specified_radius()
{
    // NYC location
    $nycLocation = Location::factory()->withCoordinates(40.7128, -74.0060)->create();
    $nycReport = WageReport::factory()->approved()->create(['location_id' => $nycLocation->id]);
    
    // LA location (3000+ km away)
    $laLocation = Location::factory()->withCoordinates(34.0522, -118.2437)->create();  
    $laReport = WageReport::factory()->approved()->create(['location_id' => $laLocation->id]);
    
    // Search within 10km of NYC
    $response = $this->getJson('/api/v1/wage-reports?near=40.7128,-74.0060&radius_km=10');
    
    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment(['id' => $nycReport->id])
        ->assertJsonMissing(['id' => $laReport->id]);
}

/** @test */
public function it_requires_authentication_for_voting()
{
    $report = WageReport::factory()->approved()->create();
    
    $response = $this->postJson("/api/v1/wage-reports/{$report->id}/vote", [
        'is_helpful' => true
    ]);
    
    $response->assertUnauthorized();
}

/** @test */
public function it_requires_moderator_role_for_approval()
{
    $user = User::factory()->viewer()->create();
    $report = WageReport::factory()->pending()->create();
    
    $response = $this->actingAs($user)
        ->patchJson("/api/v1/wage-reports/{$report->id}/approve");
    
    $response->assertForbidden();
}
```

#### TEST-G3: Performance & Integration Testing
**Commit**: `test(wage-reports): add performance tests and full integration scenarios`

**Performance Tests**:
```php
/** @test */
public function wage_report_list_endpoint_performs_within_requirements()
{
    // Create test data
    Location::factory(100)->create();
    WageReport::factory(1000)->approved()->create();
    
    $startTime = microtime(true);
    
    $response = $this->getJson('/api/v1/wage-reports?near=40.7128,-74.0060&radius_km=50');
    
    $endTime = microtime(true);
    $executionTime = ($endTime - $startTime) * 1000; // Convert to milliseconds
    
    $response->assertOk();
    $this->assertLessThan(500, $executionTime, 'API endpoint should respond within 500ms');
}

/** @test */
public function full_workflow_integration_test()
{
    // Test complete user journey
    // 1. Submit wage report
    // 2. Moderator approves
    // 3. Users vote on it
    // 4. Someone flags it
    // 5. Statistics update correctly
}
```

### H. Documentation & OpenAPI

#### DOC-H1: API Documentation
**Commit**: `docs(wage-reports): add comprehensive OpenAPI specifications and examples`

**OpenAPI Specification** for all wage report endpoints with:
- Complete request/response schemas
- Authentication requirements
- Error response examples
- Spatial query parameter documentation
- Rate limiting information

#### DOC-H2: Usage Examples
**Commit**: `docs(wage-reports): add practical usage examples and integration guides`

**Documentation includes**:
- Code examples for all endpoints
- Spatial search usage patterns
- Authentication flow examples
- Error handling best practices
- Performance optimization tips

## Task Dependency Sequence

### Phase 1: Foundation (Parallel)
- DEV-A1, DEV-A2, DEV-A3 (Models & Database)
- DOC-H1 (Start API documentation)

### Phase 2: Data Layer (After Phase 1)  
- DEV-B1, DEV-B2 (Factory & Seeder)
- TEST-G1 (Model testing starts)

### Phase 3: API Layer (After Phase 2)
- DEV-C1, DEV-C2, DEV-C3 (Resources, Validation, Controllers)
- DEV-F1 (Routes)

### Phase 4: Integration (After Phase 3)
- DEV-D1, DEV-D2 (Location & Organization integration)
- DEV-E1, DEV-E2 (Business logic services)
- DEV-F2 (Caching & rate limiting)

### Phase 5: Testing & Documentation (After Phase 4)
- TEST-G2, TEST-G3 (API & Performance testing)
- DOC-H2 (Complete documentation)

## Acceptance Criteria Summary

**For MVP Release**:
- All wage report endpoints functional with proper auth
- Spatial search working within 500ms response time
- Moderation workflow complete with audit logging
- Voting and flagging systems operational
- Integration with existing Location/Organization models
- 95%+ test coverage on all new code
- Complete API documentation
- Performance benchmarks met (500ms API responses, ±25m spatial accuracy)

**Success Metrics**:
- All tests passing (target: 450+ total tests)
- No N+1 query problems
- Proper caching implemented (5min list, 15min stats)
- Rate limiting functional
- Security audit passed (no data leaks, proper auth)

---

## PlanState YAML for Reloading

```yaml
WageReportsV1Plan:
  status: ready_for_implementation
  phase: planning_complete
  created: 2025-01-25
  
  tasks:
    development:
      DEV-A1:
        title: "Database Migration & Schema"
        status: pending
        commit: "feat(wage-reports): create comprehensive database schema with PostGIS support"
        dependencies: []
        effort: medium
        
      DEV-A2:
        title: "WageReport Model with Relationships" 
        status: pending
        commit: "feat(wage-reports): implement WageReport model with comprehensive relationships and scopes"
        dependencies: [DEV-A1]
        effort: high
        
      DEV-A3:
        title: "Supporting Models (Votes & Flags)"
        status: pending  
        commit: "feat(wage-reports): add voting and flagging support models with constraints"
        dependencies: [DEV-A1]
        effort: medium
        
      DEV-B1:
        title: "Comprehensive Factory"
        status: pending
        commit: "feat(wage-reports): create comprehensive factory with realistic wage data and states"
        dependencies: [DEV-A2, DEV-A3]
        effort: high
        
      DEV-B2:
        title: "Database Seeder"
        status: pending
        commit: "feat(wage-reports): add comprehensive seeder with real-world wage distribution" 
        dependencies: [DEV-B1]
        effort: medium
        
      DEV-C1:
        title: "API Resource Classes"
        status: pending
        commit: "feat(wage-reports): create comprehensive API resources with privacy controls"
        dependencies: [DEV-A2]
        effort: medium
        
      DEV-C2:
        title: "Form Request Validation"
        status: pending
        commit: "feat(wage-reports): implement comprehensive form request validation with business rules"
        dependencies: [DEV-A2]
        effort: medium
        
      DEV-C3:
        title: "Controller Implementation"
        status: pending
        commit: "feat(wage-reports): implement full CRUD controller with spatial search and moderation"
        dependencies: [DEV-C1, DEV-C2]
        effort: high
        
      DEV-D1:
        title: "Location Integration"
        status: pending
        commit: "feat(locations): add wage reports relationship and statistics methods"
        dependencies: [DEV-A2]
        effort: medium
        
      DEV-D2:
        title: "Organization Integration"
        status: pending
        commit: "feat(organizations): add wage reports aggregation across all locations"
        dependencies: [DEV-D1]
        effort: medium
        
      DEV-E1:
        title: "Moderation Service"
        status: pending
        commit: "feat(wage-reports): implement comprehensive moderation service with audit logging"
        dependencies: [DEV-A2, DEV-A3]
        effort: high
        
      DEV-E2:
        title: "Duplicate Detection Service"
        status: pending
        commit: "feat(wage-reports): implement intelligent duplicate detection with configurable rules"
        dependencies: [DEV-A2]
        effort: medium
        
      DEV-F1:
        title: "Route Configuration"
        status: pending
        commit: "feat(wage-reports): configure comprehensive API routes with proper middleware"
        dependencies: [DEV-C3]
        effort: low
        
      DEV-F2:
        title: "Rate Limiting & Caching"
        status: pending
        commit: "feat(wage-reports): implement intelligent caching and rate limiting strategies"
        dependencies: [DEV-F1]
        effort: medium
        
    testing:
      TEST-G1:
        title: "Model Testing"
        status: pending
        commit: "test(wage-reports): add comprehensive model testing with relationships and scopes"
        dependencies: [DEV-A2, DEV-A3, DEV-B1]
        effort: high
        
      TEST-G2:
        title: "API Testing with PostGIS"
        status: pending
        commit: "test(wage-reports): add comprehensive API testing with spatial queries and authentication"
        dependencies: [DEV-C3, DEV-F1]
        effort: high
        
      TEST-G3:
        title: "Performance & Integration Testing"
        status: pending
        commit: "test(wage-reports): add performance tests and full integration scenarios"
        dependencies: [DEV-F2, TEST-G2]
        effort: medium
        
    documentation:
      DOC-H1:
        title: "API Documentation"
        status: pending
        commit: "docs(wage-reports): add comprehensive OpenAPI specifications and examples"
        dependencies: [DEV-C3]
        effort: medium
        
      DOC-H2:
        title: "Usage Examples"
        status: pending  
        commit: "docs(wage-reports): add practical usage examples and integration guides"
        dependencies: [DOC-H1, TEST-G2]
        effort: low
        
  key_metrics:
    target_test_count: "450+"
    current_test_count: "349"
    required_api_response_time: "500ms"
    spatial_accuracy_tolerance: "25m"
    cache_ttl_list: "5min"
    cache_ttl_stats: "15min"
    
  architecture:
    database: "PostgreSQL 17 + PostGIS 3.5"
    framework: "Laravel 12"
    php_version: "8.3+"
    testing: "PHPUnit"
    auth: "Laravel Sanctum"
    spatial: "clickbar/laravel-magellan"
    
  completion_criteria:
    - All 16 tasks completed successfully
    - 95%+ test coverage on new code
    - All performance benchmarks met
    - Complete API documentation
    - Security audit passed
    - Integration with existing models working
    - Moderation workflow operational
```