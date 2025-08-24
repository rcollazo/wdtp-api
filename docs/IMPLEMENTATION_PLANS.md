# WDTP Implementation Plans

This document contains comprehensive implementation plans for major WDTP features. Each plan provides complete technical specifications, database schemas, API endpoints, and step-by-step task breakdowns ready for execution.

## Table of Contents

- [Locations Implementation Plan](#locations-implementation-plan)

---

## Locations Implementation Plan

# Plan: WDTP Locations v1

## A) Rationale and Alignment

The Locations feature represents the physical address layer of the WDTP platform, where organizations have specific brick-and-mortar locations where wage reports are submitted. Locations are the critical spatial component that enables geographic search and proximity-based wage transparency.

**Alignment with Organizations:**
- Same slug-based routing with ID fallback
- Identical caching strategy with versioned keys  
- Consistent search patterns using PostgreSQL ILIKE
- Default read filters (active, visible, approved status)
- OpenAPI inline documentation approach
- Comprehensive unit and feature test coverage

**Spatial Enhancement:**
- PostGIS geography(Point,4326) for accurate distance calculations
- Spatial queries with ST_DWithin and ST_Distance functions
- Geographic search with `near=lat,lon&radius_km=N` parameters
- Distance calculations returned in API responses

**Integration with Existing Architecture:**
- Foreign key relationship to organizations (already prepared)
- Future foundation for wage reports (locations.wage_reports)
- Industry filtering through organization relationships
- User attribution for location creation and verification

## B) File Tree Structure

```
database/migrations/
├── 2025_08_24_120000_create_locations_table.php
└── 2025_08_24_120001_add_location_search_indexes.php

app/Models/
└── Location.php

app/Observers/
└── LocationObserver.php

app/Http/Controllers/Api/V1/
└── LocationController.php

app/Http/Resources/
├── LocationResource.php
├── LocationListItemResource.php
└── LocationMinimalResource.php

routes/
└── api.php (modifications)

tests/Unit/Models/
└── LocationTest.php

tests/Feature/Api/
└── LocationsApiTest.php
```

## C) API Endpoints Structure

The Locations API will provide 5 endpoints:

1. **GET /api/v1/locations** - Index with spatial search
   - Parameters: `near`, `radius_km`, `organization_id`, `city`, `state_province`, `verified`, `per_page`, `sort`
   - Spatial search with distance calculation when `near` parameter provided
   - Returns paginated LocationListItemResource with `distance_meters`

2. **GET /api/v1/locations/autocomplete** - Location search suggestions
   - Parameters: `q`, `near` (optional), `radius_km`, `limit`
   - Returns minimal format: `{id, name, slug, full_address, distance_meters?}`

3. **GET /api/v1/locations/nearby** - Dedicated proximity search
   - Required: `near=lat,lon`, `radius_km`
   - Returns locations sorted by distance with `distance_meters`

4. **GET /api/v1/locations/{idOrSlug}** - Show location details
   - Optional: `near` parameter adds `distance_meters` to response
   - Returns full LocationResource with organization relationship

5. **GET /api/v1/locations/{idOrSlug}/wage-reports** - Future wage reports endpoint
   - Returns wage reports for specific location (implementation in Step 7)

## D) Database Schema Design

### Migration: create_locations_table.php

```php
Schema::create('locations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('organization_id')->nullable()
          ->constrained('organizations')->onDelete('cascade');
    $table->string('name', 160); // "Starbucks - Downtown Seattle"
    $table->string('slug', 160)->unique();
    $table->text('address'); // Full street address
    $table->string('city', 100);
    $table->string('state_province', 100);
    $table->string('postal_code', 20);
    $table->string('country_code', 2)->default('US');
    $table->geography('point', 'POINT', 4326); // PostGIS coordinates
    $table->decimal('latitude', 10, 8); // Cached for quick access
    $table->decimal('longitude', 11, 8); // Cached for quick access
    $table->string('phone', 20)->nullable();
    $table->json('hours')->nullable(); // Operating hours
    $table->text('description')->nullable();
    $table->enum('status', ['approved', 'pending', 'rejected'])->default('pending');
    $table->enum('verification_status', ['unverified', 'verified', 'rejected'])
          ->default('unverified');
    $table->foreignId('created_by')->nullable()->constrained('users');
    $table->foreignId('verified_by')->nullable()->constrained('users');
    $table->timestamp('verified_at')->nullable();
    $table->integer('wage_reports_count')->default(0);
    $table->boolean('is_active')->default(true);
    $table->boolean('visible_in_ui')->default(true);
    $table->timestamps();

    // Indexes
    $table->index(['is_active', 'visible_in_ui', 'status']);
    $table->index(['organization_id', 'status']);
    $table->index(['city', 'state_province', 'country_code']);
});

// PostGIS spatial index
DB::statement('CREATE INDEX locations_point_gist_idx ON locations USING GIST (point)');
// Address search index  
DB::statement('CREATE INDEX locations_address_gin_idx ON locations USING GIN (to_tsvector(\'english\', address || \' \' || city))');
```

## E) Spatial Query Implementation

### Distance-Based Search Pattern

```php
// ST_DWithin for filtering (uses spatial index)
$query->whereRaw(
    'ST_DWithin(point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
    [$longitude, $latitude, $radiusMeters]
);

// ST_Distance for sorting and distance_meters calculation
$query->selectRaw(
    'locations.*, ST_Distance(point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_meters',
    [$longitude, $latitude]
)->orderBy('distance_meters');
```

### Required Query Parameters

```
?near=40.7128,-74.0060&radius_km=5          # Spatial search
?organization_slug=starbucks&near=lat,lon    # Organization + spatial
?city=Seattle&state_province=WA              # Geographic filtering
?verified=true&per_page=50                   # Status filtering
```

## F) Cache Strategy

### Version Management
- Key: `locations:ver` (integer, default 1)
- Increment on any Location save/delete

### Cache Keys & TTLs
- `locations:{ver}:index:{hash}` - 300s (5 minutes)
  - Hash includes: near, radius_km, organization_id, city, state_province, verified, per_page, sort
- `locations:{ver}:show:{idOrSlug}` - 300s (5 minutes)
- `locations:{ver}:ac:{hash}` - 600s (10 minutes)
  - Hash includes: q, near, radius_km, limit
- `locations:{ver}:nearby:{hash}` - 180s (3 minutes, shorter due to real-time nature)
  - Hash includes: near, radius_km, per_page

### Spatial Cache Considerations
- Spatial queries are more expensive, so caching is critical
- Nearby endpoint has shorter TTL due to real-time location context
- Distance calculations cached to avoid repeated PostGIS computations

## G) Testing Strategy with Real Coordinates

### Spatial Test Data

```php
// Use realistic US metropolitan coordinates
$seattle = ['lat' => 47.6062, 'lon' => -122.3321];
$portland = ['lat' => 45.5152, 'lon' => -122.6784]; 
$vancouver = ['lat' => 49.2827, 'lon' => -123.1207];
$spokane = ['lat' => 47.6587, 'lon' => -117.4260];

// Test distances (approximate)
// Seattle to Portland: ~278 km
// Seattle to Vancouver: ~195 km  
// Seattle to Spokane: ~359 km
```

### Test Scenarios

```php
// Distance accuracy testing
Location::factory()->withCoordinates(47.6062, -122.3321)->create(); // Seattle
Location::factory()->withCoordinates(45.5152, -122.6784)->create(); // Portland

$nearbyLocations = Location::near(47.6062, -122.3321, 300)->withDistance(47.6062, -122.3321)->get();
$this->assertEqualsWithDelta(278000, $nearbyLocations[1]->distance_meters, 5000); // ±5km tolerance
```

## K) Multi-Agent Task Assignments

### TASK PAIR 1/6: Database Foundation & PostGIS Setup

**DEV TASK 1/6 → wdtp-api-dev**
- Goal: Create locations table with PostGIS spatial capabilities and performance indexes
- Dependencies: Organizations table (for FK relationship)
- Files: database/migrations/2025_08_24_120000_create_locations_table.php, database/migrations/2025_08_24_120001_add_location_search_indexes.php
- Technical Requirements: PostGIS geography(Point,4326), spatial indexes (GiST), address search indexes (GIN), coordinate validation constraints
- Acceptance Criteria: PostGIS extension verified, spatial indexes created, coordinate validation enforced, migration rollback tested
- Commit Message: feat(db): add locations table with PostGIS spatial capabilities and search indexes

**TEST TASK 1/6 → wdtp-api-testing**  
- Goal: Create comprehensive test suite for Location database schema and PostGIS functionality
- Dependencies: DEV TASK 1/6 completion
- Files: tests/Unit/Database/LocationMigrationTest.php, tests/Unit/Models/LocationSpatialTest.php
- Test Requirements: Migration rollback/forward testing, PostGIS extension verification, spatial index performance testing, coordinate constraint validation
- Acceptance Criteria: All database constraints tested, spatial index functionality verified, coordinate validation edge cases covered
- Commit Message: test(db): add comprehensive Location database and PostGIS test suite

**DOC TASK 1/6 → wdtp-api-docs-maintainer**
- Goal: Document Location database schema, PostGIS setup, and spatial capabilities
- Dependencies: DEV TASK 1/6 and TEST TASK 1/6 completion with DocsDelta
- Files: docs/DATABASE.md (add Locations section), docs/SPATIAL.md (new file for PostGIS documentation), docs/CHANGELOG.md
- Documentation Requirements: Complete table structure, PostGIS configuration, spatial index strategy, coordinate system explanation
- Acceptance Criteria: All Location schema documented, PostGIS setup instructions included, spatial query patterns documented
- Commit Message: docs(db): add Location schema and PostGIS spatial capabilities documentation

### TASK PAIR 2/6: Core Model & Spatial Observer

**DEV TASK 2/6 → wdtp-api-dev**
- Goal: Implement Location model with spatial scopes and PostGIS integration
- Dependencies: DEV TASK 1/6 (database schema)
- Files: app/Models/Location.php, app/Observers/LocationObserver.php, app/Providers/AppServiceProvider.php
- Technical Requirements: Spatial scopes (near, withDistance, withinBounds), PostGIS point synchronization, cache version management, coordinate validation
- Acceptance Criteria: All spatial scopes return accurate calculations, PostGIS point auto-updated, cache invalidation working, coordinate validation prevents invalid values
- Commit Message: feat(models): add Location model with PostGIS spatial scopes and coordinate validation

**TEST TASK 2/6 → wdtp-api-testing**
- Goal: Create comprehensive test suite for Location model and spatial functionality
- Dependencies: DEV TASK 2/6 completion
- Files: tests/Unit/Models/LocationTest.php, tests/Unit/Models/LocationSpatialScopesTest.php, tests/Unit/Observers/LocationObserverTest.php
- Test Requirements: All scopes tested with real coordinates, distance calculation accuracy (±5km tolerance), cache behavior verification, coordinate validation edge cases
- Acceptance Criteria: Spatial scopes tested with Seattle/Portland/Vancouver coordinates, distance calculations accurate, cache invalidation verified, observer behavior tested
- Commit Message: test(models): add comprehensive Location model and spatial scopes test suite

**DOC TASK 2/6 → wdtp-api-docs-maintainer**  
- Goal: Document Location model capabilities, spatial scopes, and observer behavior
- Dependencies: DEV TASK 2/6 and TEST TASK 2/6 completion with DocsDelta
- Files: docs/MODELS.md (add Location section), docs/SPATIAL.md (update with model documentation), docs/CACHING.md (add location cache strategy)
- Documentation Requirements: All spatial scopes with examples, coordinate validation rules, cache strategy, observer behavior documentation
- Acceptance Criteria: All model capabilities documented, spatial scope usage examples included, cache invalidation strategy explained
- Commit Message: docs(models): add Location model and spatial functionality documentation

### TASK PAIR 3/6: Spatial API Resources

**DEV TASK 3/6 → wdtp-api-dev**
- Goal: Create location API resources with distance calculation support
- Dependencies: DEV TASK 2/6 (Location model)
- Files: app/Http/Resources/LocationResource.php, app/Http/Resources/LocationListItemResource.php, app/Http/Resources/LocationMinimalResource.php
- Technical Requirements: Conditional distance_meters field, organization relationship formatting, spatial data consistency across resources
- Acceptance Criteria: Resources handle spatial data consistently, distance calculations included when available, organization relationships formatted properly
- Commit Message: feat(api): add Location API resources with spatial distance calculations

**TEST TASK 3/6 → wdtp-api-testing**
- Goal: Create comprehensive test suite for Location API resources
- Dependencies: DEV TASK 3/6 completion  
- Files: tests/Unit/Resources/LocationResourceTest.php, tests/Unit/Resources/LocationListItemResourceTest.php, tests/Unit/Resources/LocationMinimalResourceTest.php
- Test Requirements: Resource formatting with/without relationships, conditional distance_meters field, resource inheritance testing, organization relationship formatting
- Acceptance Criteria: All resource formats tested, conditional field inclusion verified, resource inheritance working, relationship formatting correct
- Commit Message: test(api): add comprehensive Location API resources test suite

**DOC TASK 3/6 → wdtp-api-docs-maintainer**
- Goal: Document Location API resource structure and spatial field handling  
- Dependencies: DEV TASK 3/6 and TEST TASK 3/6 completion with DocsDelta
- Files: docs/API_RESOURCES.md (add Location section), docs/SPATIAL.md (update with API resource documentation)
- Documentation Requirements: Resource field mappings, conditional distance_meters documentation, inheritance patterns, spatial data formatting
- Acceptance Criteria: All resource fields documented with examples, spatial field handling explained, inheritance pattern documented
- Commit Message: docs(api): add Location API resources and spatial field documentation

### TASK PAIR 4/6: Core Spatial API Controller

**DEV TASK 4/6 → wdtp-api-dev**  
- Goal: Implement LocationController with spatial search endpoints
- Dependencies: DEV TASK 3/6 (API resources)
- Files: app/Http/Controllers/Api/V1/LocationController.php
- Technical Requirements: Index/show endpoints with spatial parameters, coordinate validation, spatial query optimization, caching strategy
- Acceptance Criteria: Spatial search works with real coordinates, distance calculations accurate (±5km), caching works with spatial variations, coordinate validation prevents invalid input
- Commit Message: feat(api): add Location controller with PostGIS spatial search capabilities

**TEST TASK 4/6 → wdtp-api-testing**
- Goal: Create comprehensive test suite for Location API controller spatial functionality
- Dependencies: DEV TASK 4/6 completion
- Files: tests/Feature/Api/LocationsApiTest.php, tests/Feature/Api/LocationsSpatialTest.php
- Test Requirements: Spatial search testing with real coordinates, distance calculation validation, parameter validation, cache behavior verification, error handling
- Acceptance Criteria: All spatial endpoints tested with Seattle/Portland coordinates, distance accuracy verified, parameter validation working, cache behavior tested
- Commit Message: test(api): add comprehensive Location API controller and spatial search test suite

**DOC TASK 4/6 → wdtp-api-docs-maintainer**
- Goal: Document Location API endpoints with spatial search capabilities
- Dependencies: DEV TASK 4/6 and TEST TASK 4/6 completion with DocsDelta  
- Files: docs/API.md (add Location endpoints), docs/SPATIAL.md (update with API documentation), docs/ROUTES.md, docs/CACHING.md
- Documentation Requirements: Complete endpoint documentation, spatial parameter examples, coordinate validation rules, cache strategy documentation
- Acceptance Criteria: All endpoints documented with examples, spatial parameters explained, coordinate validation documented, cache strategy included
- Commit Message: docs(api): add Location API endpoints and spatial search documentation

### TASK PAIR 5/6: Nearby & Autocomplete Endpoints

**DEV TASK 5/6 → wdtp-api-dev**
- Goal: Add specialized spatial endpoints for nearby search and location autocomplete
- Dependencies: DEV TASK 4/6 (base controller)
- Files: app/Http/Controllers/Api/V1/LocationController.php (add nearby and autocomplete methods)
- Technical Requirements: Dedicated nearby endpoint, spatial autocomplete filtering, optimized caching, minimal response formats
- Acceptance Criteria: Nearby endpoint sorts by distance accurately, autocomplete provides relevant suggestions, spatial filtering improves relevance, response times under 200ms
- Commit Message: feat(api): add Location nearby search and spatial autocomplete endpoints

**TEST TASK 5/6 → wdtp-api-testing**
- Goal: Create comprehensive test suite for Location nearby and autocomplete endpoints  
- Dependencies: DEV TASK 5/6 completion
- Files: tests/Feature/Api/LocationsNearbyTest.php, tests/Feature/Api/LocationsAutocompleteTest.php  
- Test Requirements: Nearby endpoint distance sorting, autocomplete relevance testing, spatial filtering validation, performance testing, cache behavior
- Acceptance Criteria: Distance sorting accuracy verified, autocomplete relevance tested, spatial filtering working, performance benchmarks met, cache behavior validated
- Commit Message: test(api): add comprehensive Location nearby and autocomplete endpoint test suite

**DOC TASK 5/6 → wdtp-api-docs-maintainer**
- Goal: Document Location nearby and autocomplete endpoints with performance details
- Dependencies: DEV TASK 5/6 and TEST TASK 5/6 completion with DocsDelta
- Files: docs/API.md (update with nearby/autocomplete), docs/PERFORMANCE.md (add location performance notes), docs/SPATIAL.md
- Documentation Requirements: Nearby endpoint documentation, autocomplete functionality, performance characteristics, spatial filtering examples
- Acceptance Criteria: Nearby endpoint documented with examples, autocomplete functionality explained, performance characteristics documented, spatial filtering examples included
- Commit Message: docs(api): add Location nearby and autocomplete endpoint documentation with performance details

### TASK PAIR 6/6: Route Registration & Spatial Integration

**DEV TASK 6/6 → wdtp-api-dev**
- Goal: Register all location routes and ensure complete spatial API integration
- Dependencies: DEV TASK 5/6 (complete controller)
- Files: routes/api.php (add location routes)
- Technical Requirements: Register all 5 endpoints with proper ordering, verify spatial query performance, complete OpenAPI documentation, integration testing
- Acceptance Criteria: All routes resolve correctly, spatial queries perform efficiently, OpenAPI documentation correct, full API workflow tested
- Commit Message: feat(api): register Location API routes and complete spatial integration

**TEST TASK 6/6 → wdtp-api-testing**
- Goal: Create comprehensive integration test suite for complete Location API
- Dependencies: DEV TASK 6/6 completion
- Files: tests/Feature/Api/LocationsIntegrationTest.php, tests/Feature/Api/LocationsPerformanceTest.php
- Test Requirements: Full API workflow testing, route resolution verification, integration with Organizations API, performance benchmarking under load
- Acceptance Criteria: Complete API workflow tested, route integration verified, Organizations integration working, performance benchmarks met, no regressions in existing tests
- Commit Message: test(api): add comprehensive Location API integration and performance test suite

**DOC TASK 6/6 → wdtp-api-docs-maintainer**
- Goal: Document complete Location API integration and update project status
- Dependencies: DEV TASK 6/6 and TEST TASK 6/6 completion with DocsDelta
- Files: docs/API.md (finalize Location section), docs/ROUTES.md (complete route table), docs/TESTING.md (update test counts), docs/CHANGELOG.md, CLAUDE.md (update status)
- Documentation Requirements: Complete Location API integration, route documentation, test count updates, project status change from TODO to COMPLETE
- Acceptance Criteria: Complete Location API documented, all routes in route table, test counts updated, CLAUDE.md shows Locations as COMPLETE
- Commit Message: docs(api): finalize Location API documentation and update project status to COMPLETE

## L) Task Execution Protocol

**Multi-Agent Handoff Pattern:**
1. Execute DEV TASK N/6 → wdtp-api-dev
2. Upon DEV completion, execute TEST TASK N/6 → wdtp-api-testing  
3. Upon TEST completion, execute DOC TASK N/6 → wdtp-api-docs-maintainer
4. Wait for "NEXT" command before proceeding to next task pair
5. Continue until all 6 task pairs complete

**Expected Timeline:**
- Total: 18 tasks (6 DEV + 6 TEST + 6 DOC)
- Estimated: 15-20 hours of implementation time
- Deliverable: Complete Location API with 90+ passing tests and full documentation

**Integration Verification:**
- No regressions in existing 153 tests
- New Location tests increase total to 240+ tests
- Complete OpenAPI documentation generation
- Full spatial query functionality verified

## I) Expected Deliverables

- **Database:** 1 main migration + 1 index migration with PostGIS setup
- **Models:** 1 model with spatial relationships and 12+ scopes
- **API:** 5 endpoints with full spatial search capabilities
- **Resources:** 3 API resources for different response formats
- **Tests:** 90+ tests covering spatial functionality (Unit: 50+, Feature: 40+)
- **Performance:** Spatial indexing and query optimization
- **Documentation:** Complete OpenAPI/Swagger with spatial parameter examples

## J) Integration Points

- **Organizations:** Foreign key relationship and filtering through org
- **Users:** Authentication and location creation attribution  
- **Future Wage Reports:** One-to-many relationship foundation
- **PostGIS:** Spatial database capabilities for geographic queries
- **Cache System:** Versioned cache keys with spatial query hashing

This architecture provides the spatial foundation for wage transparency while maintaining consistency with the proven Organizations implementation pattern. The comprehensive spatial testing strategy ensures accurate distance calculations and optimal performance for location-based queries.

**Implementation Status:** Ready for execution following the 18-task multi-agent workflow (6 DEV tasks + 6 TEST tasks + 6 DOC tasks) with specialized agent assignments for comprehensive implementation, testing, and documentation.

---

## Future Implementation Plans

Additional major features will be documented here as they are planned:

- Position Categories Implementation
- Wage Reports Implementation  
- User Interactions (Voting/Flagging) Implementation
- Gamification System Implementation
- Analytics & Statistics Implementation

Each plan will follow the same comprehensive structure with rationale, technical specifications, task breakdown, and integration points.