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

## H) DEV TASKS (Implementation WBS)

### DEV TASK 1/6: Database Foundation & PostGIS Setup

**Goal:** Create locations table with PostGIS spatial capabilities and performance indexes

**Dependencies:** Organizations table (for FK relationship)

**Files:**
- `database/migrations/2025_08_24_120000_create_locations_table.php`
- `database/migrations/2025_08_24_120001_add_location_search_indexes.php`

**Design Notes:**
- PostGIS geography(Point,4326) for accurate distance calculations
- Cached lat/lon columns for quick access without PostGIS functions
- Comprehensive address fields for US/international locations
- Performance indexes: GiST spatial, GIN full-text search, composite filtering

**Acceptance Criteria:**
- PostGIS extension verified and spatial indexes created
- Coordinate validation constraints enforced
- Address search performance optimized with GIN index
- Migration rollback properly removes spatial objects

**Commit Message:** `feat(db): add locations table with PostGIS spatial capabilities and search indexes`

### DEV TASK 2/6: Core Model & Spatial Observer

**Goal:** Implement Location model with spatial scopes and PostGIS integration

**Dependencies:** DEV TASK 1 (database schema)

**Files:**
- `app/Models/Location.php`
- `app/Observers/LocationObserver.php`
- `app/Providers/AppServiceProvider.php` (register observer, cache bootstrap)

**Design Notes:**
- Spatial scopes using ST_DWithin and ST_Distance functions
- PostGIS point synchronization from lat/lon coordinates
- Cache version management following Organizations pattern
- Route binding with slug-or-ID support

**Acceptance Criteria:**
- All spatial scopes return accurate distance calculations
- PostGIS point automatically updated when coordinates change
- Cache invalidation triggers on location changes
- Coordinate validation prevents invalid lat/lon values

**Commit Message:** `feat(models): add Location model with PostGIS spatial scopes and coordinate validation`

### DEV TASK 3/6: Spatial API Resources

**Goal:** Create location API resources with distance calculation support

**Dependencies:** DEV TASK 2 (Location model)

**Files:**
- `app/Http/Resources/LocationResource.php`
- `app/Http/Resources/LocationListItemResource.php`
- `app/Http/Resources/LocationMinimalResource.php`

**Design Notes:**
- LocationListItemResource for index endpoint with spatial data
- LocationMinimalResource for autocomplete performance
- Conditional distance_meters field when spatial query used
- Organization relationship as inline object {id,name,slug}

**Acceptance Criteria:**
- Resources handle spatial data consistently
- Distance calculations included when available
- Organization relationships formatted properly
- Resource inheritance maintains consistent field structure

**Commit Message:** `feat(api): add Location API resources with spatial distance calculations`

### DEV TASK 4/6: Core Spatial API Controller

**Goal:** Implement LocationController with spatial search endpoints

**Dependencies:** DEV TASK 3 (API resources)

**Files:**
- `app/Http/Controllers/Api/V1/LocationController.php`

**Design Notes:**
- Index endpoint with `near` and `radius_km` spatial parameters
- Show endpoint with optional distance calculation
- Advanced spatial filtering with geographic bounds
- Caching strategy optimized for spatial queries

**Acceptance Criteria:**
- Spatial search works with real coordinates
- Distance calculations accurate within 5km tolerance
- Caching system works with spatial query variations
- Parameter validation prevents invalid coordinates

**Commit Message:** `feat(api): add Location controller with PostGIS spatial search capabilities`

### DEV TASK 5/6: Nearby & Autocomplete Endpoints

**Goal:** Add specialized spatial endpoints for nearby search and location autocomplete

**Dependencies:** DEV TASK 4 (base controller)

**Files:**
- `app/Http/Controllers/Api/V1/LocationController.php` (add methods)

**Design Notes:**
- Dedicated `/nearby` endpoint requiring spatial parameters
- Location autocomplete with optional spatial filtering
- Optimized caching for real-time spatial queries
- Minimal response formats for performance

**Acceptance Criteria:**
- Nearby endpoint sorts results by distance accurately
- Autocomplete provides relevant location suggestions
- Spatial filtering improves autocomplete relevance
- Response times under 200ms for typical queries

**Commit Message:** `feat(api): add Location nearby search and spatial autocomplete endpoints`

### DEV TASK 6/6: Route Registration & Spatial Integration

**Goal:** Register all location routes and ensure complete spatial API integration

**Dependencies:** DEV TASK 5 (complete controller)

**Files:**
- `routes/api.php` (add location routes)

**Design Notes:**
- Register all 5 location endpoints with proper ordering
- Verify spatial query performance under load
- Complete OpenAPI documentation with spatial examples
- Integration testing with real geographic data

**Acceptance Criteria:**
- All location routes resolve and function correctly
- Spatial queries perform efficiently with indexes
- OpenAPI documentation shows spatial parameters correctly
- Full location API workflow tested end-to-end

**Commit Message:** `feat(api): register Location API routes and complete spatial integration`

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

**Implementation Status:** Ready for execution following the 6-task DEV/DOC workflow established with Organizations.

---

## Future Implementation Plans

Additional major features will be documented here as they are planned:

- Position Categories Implementation
- Wage Reports Implementation  
- User Interactions (Voting/Flagging) Implementation
- Gamification System Implementation
- Analytics & Statistics Implementation

Each plan will follow the same comprehensive structure with rationale, technical specifications, task breakdown, and integration points.