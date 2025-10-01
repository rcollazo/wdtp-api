# WDTP API — Sprint 3: Unified Location Search with OSM Integration

## Request from wdtp-ui-architect

The UI team has requested a unified location search endpoint to support the search interface (UI-020). The requirement combines WDTP database locations with OpenStreetMap POIs to provide comprehensive search results.

### Backend API Requirements

**Endpoint**: `GET /api/v1/locations/search`

**Required Parameters**:
- `q` (string) - Search query, minimum 2 characters. Example: "mcdonald's" or "restaurants"
- `lat` (float) - Center latitude for spatial search. Range: -90 to 90
- `lng` (float) - Center longitude for spatial search. Range: -180 to 180

**Optional Parameters**:
- `radius_km` (float) - Search radius in kilometers. Default: 10, Maximum: 50
- `include_osm` (boolean) - Include OSM POIs not in WDTP database. Default: false
- `min_wage_reports` (integer) - Filter locations with minimum N wage reports
- `per_page` (integer) - Results per page. Default: 100, Maximum: 500

**Response Structure** (200 OK):
```json
{
  "data": [
    {
      "source": "wdtp",
      "location_id": 123,
      "osm_id": "node/123456",
      "osm_type": "node",
      "name": "McDonald's - Times Square",
      "latitude": 40.758,
      "longitude": -73.985,
      "has_wage_data": true,
      "wage_reports_count": 15,
      "address": "1234 Broadway, New York, NY",
      "organization": {
        "id": 5,
        "name": "McDonald's Corporation",
        "slug": "mcdonalds"
      },
      "distance_meters": 450,
      "relevance_score": 0.95
    }
  ],
  "meta": {
    "total": 47,
    "wdtp_count": 12,
    "osm_count": 35,
    "search_query": "restaurants",
    "search_type": "category",
    "center": {
      "lat": 40.758,
      "lng": -73.985
    },
    "radius_km": 10
  }
}
```

**Error Responses**:
- 422 Unprocessable Entity - Validation errors (missing/invalid parameters)
- 503 Service Unavailable - Overpass API unavailable (only if include_osm=true fails)

---

## Executive Summary
- **Feature**: Unified location search endpoint combining WDTP database + OpenStreetMap POIs
- **Endpoint**: `GET /api/v1/locations/search`
- **Status**: Planning Complete → Ready for Implementation
- **Branch**: `feat/location-search-unified-sprint3`
- **Estimated Effort**: 3 weeks (22 tasks: 12 implementation + 10 testing)

## Goals
1. Implement `/api/v1/locations/search` endpoint with comprehensive query capabilities
2. Integrate OpenStreetMap (OSM) via local Overpass API server (with existing cache layer)
3. Combine WDTP + OSM results with intelligent relevance scoring
4. Maintain strict performance targets: <200ms WDTP-only, <2s unified queries
5. Ensure graceful degradation when OSM service unavailable

## Scope

### In-Scope
- Full-text search on location names and addresses
- Category-based search (e.g., "restaurants", "retail")
- Spatial search with radius filtering (10km default, 50km max)
- Optional OSM POI integration via `include_osm` parameter
- Minimum wage reports filtering
- Unified response format with relevance scoring
- Comprehensive validation and error handling
- Leverage existing Overpass server cache (no additional cache layer needed)
- Performance benchmarking and optimization
- Comprehensive test suite (unit, feature, integration)

### Out-of-Scope
- Machine learning-based category detection (future enhancement)
- User-submitted OSM corrections
- Real-time OSM data synchronization
- Rate limiting (can be added later if needed)
- Additional application cache layer (Overpass server already has caching)
- Advanced analytics on search queries

## Architecture Overview

### Data Flow Diagram
```
┌─────────────────────────────────────────────────────────────┐
│  Client Request: GET /api/v1/locations/search?q=...        │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
          ┌──────────────────────────────┐
          │  LocationSearchRequest       │
          │  (Validation Layer)          │
          └──────────┬───────────────────┘
                     │
                     ▼
          ┌──────────────────────────────┐
          │  LocationController@search   │
          │  (Orchestration Layer)       │
          └──────────┬───────────────────┘
                     │
        ┌────────────┴────────────┐
        ▼                         ▼
┌───────────────┐         ┌──────────────────────┐
│ WDTP Database │         │ OverpassService      │
│ (PostgreSQL + │         │ (Connects to server  │
│  PostGIS)     │         │  with built-in cache)│
└───────┬───────┘         └──────────┬───────────┘
        │                            │
        │                            ▼
        │                  ┌────────────────────┐
        │                  │ Local Overpass API │
        │                  │ 10.192.50.3:8082   │
        │                  │ (Has Cache Layer)  │
        │                  └─────────┬──────────┘
        │                            │
        ▼                            ▼
┌────────────────────────────────────────┐
│    Result Merger & Relevance Scorer    │
└────────────┬───────────────────────────┘
             │
             ▼
┌────────────────────────────────────────┐
│    UnifiedLocationResource Collection  │
└────────────┬───────────────────────────┘
             │
             ▼
┌────────────────────────────────────────┐
│  JSON Response with data[] + meta{}    │
└────────────────────────────────────────┘
```

### System Components
```
┌─────────────────────────────────────────────────────┐
│                   API Layer                         │
├─────────────────────────────────────────────────────┤
│ - LocationSearchRequest (validation)                │
│ - LocationController@search (orchestration)         │
│ - UnifiedLocationResource (response transformation) │
└─────────────────────────────────────────────────────┘
                         │
        ┌────────────────┴────────────────┐
        ▼                                 ▼
┌──────────────────┐           ┌──────────────────┐
│  Domain Layer    │           │  Integration     │
├──────────────────┤           ├──────────────────┤
│ - Location Model │           │ - OverpassService│
│ - Search Scopes  │           │ - OsmLocation DTO│
│ - RelevanceScorer│           └──────────────────┘
└──────────────────┘
        │
        ▼
┌──────────────────┐
│ Database Layer   │
├──────────────────┤
│ - Full-text index│
│ - Spatial indexes│
│ - PostGIS queries│
└──────────────────┘
```

## Architecture Decisions

### 1. Service-Oriented OSM Integration

**Decision**: Isolate all Overpass API communication in dedicated `OverpassService` class.

**Rationale**:
- **Testability**: Can mock HTTP responses without hitting Overpass server
- **Error Isolation**: OSM failures don't cascade to WDTP search
- **Reusability**: Service can be used by future features (wage report search, etc.)
- **Maintainability**: All Overpass QL query logic centralized
- **Cache Transparency**: Leverages existing Overpass server cache without additional complexity

**Trade-offs**:
- Adds abstraction layer vs inline HTTP calls
- **Benefit outweighs cost** for production system reliability

**Alternatives Considered**:
- Inline HTTP in controller: Rejected (poor testability, coupling)
- Repository pattern: Rejected (unnecessary for external API)

### 2. No Additional Cache Layer (Simplified)

**Decision**: Rely on existing Overpass server cache, no application-level OSM cache.

**Rationale**:
- **Existing Infrastructure**: Overpass server at 10.192.50.3:8082 already has caching
- **Simplicity**: Fewer moving parts, less complexity to maintain
- **Performance**: Server-side cache eliminates network round-trip
- **Reliability**: Server cache is professionally maintained
- **Development Speed**: Removes entire task from implementation

**Trade-offs**:
- Less control over cache invalidation
- Cannot customize cache TTL or eviction policies
- **Acceptable** given stable nature of OSM POI data

**Future Consideration**: If Overpass server cache proves insufficient, can add application cache layer later without API changes.

### 3. Hybrid Resource Pattern (UnifiedLocationResource)

**Decision**: Single resource class handles both WDTP Location models and OSM DTOs.

**Rationale**:
- **Consistent API**: UI receives identical format regardless of source
- **Simpler Client**: No conditional logic needed for different schemas
- **Conditional Fields**: Organization data only for WDTP locations (null for OSM)
- **Laravel Convention**: Follows standard API Resource pattern
- **Maintainability**: Single transformation point for both data sources

**Trade-offs**:
- Resource class has conditional logic (instanceof checks)
- Slightly more complex than separate resources
- **Acceptable** for cleaner API contract

**Alternatives Considered**:
- Separate WdtpLocationResource and OsmLocationResource: Rejected (API inconsistency)
- Polymorphic collection: Rejected (over-engineering for two types)

### 4. Relevance Scoring Algorithm

**Formula**:
```
relevance_score = (text_match_score × 0.6) + (proximity_score × 0.4)

Where:
- text_match_score: PostgreSQL ts_rank() normalized to 0-1 scale
- proximity_score: 1 - (distance_meters / max_radius_meters)
```

**Rationale**:
- **Text Priority (60%)**: Name/category match is primary user intent
- **Proximity Secondary (40%)**: Distance matters but shouldn't override relevance
- **Normalization**: Both scores 0-1 scale ensures balanced weighting
- **Simplicity**: Easy to understand, debug, and adjust weights
- **No ML Required**: Deterministic algorithm sufficient for MVP

**Trade-offs**:
- Fixed weighting may not suit all use cases
- Doesn't learn from user behavior
- **Acceptable** for initial release, can enhance later

**Future Enhancement**: Machine learning model trained on user click-through data

### 5. Error Handling & Graceful Degradation

**Strategy**: OSM failures never prevent WDTP results from returning.

**Error Scenarios**:

| Error Type | HTTP Status | Response Behavior | Logging |
|------------|-------------|-------------------|---------|
| OSM Timeout (>10s) | 200 OK | WDTP-only, `meta.osm_unavailable: true` | Warning |
| OSM 503 Unavailable | 200 OK | WDTP-only, `meta.osm_unavailable: true` | Warning |
| OSM Connection Error | 200 OK | WDTP-only, `meta.osm_unavailable: true` | Error |
| Invalid Coordinates | 422 | Validation error response | Info |
| Database Error | 500 | Standard error response | Critical |

**Rationale**:
- **User Experience**: Partial results better than complete failure
- **Reliability**: External service failures don't break core functionality
- **Transparency**: Meta flags inform UI about degraded mode
- **Monitoring**: Logging enables ops team to detect recurring issues
- **SLA Protection**: WDTP availability not dependent on OSM uptime

**Trade-offs**:
- Users may not realize OSM is unavailable (only shown in meta)
- **Acceptable** with proper UI handling of meta flags

---

## Task Breakdown

### Phase 1: Database & Model Foundation (Implementation)

#### Task 1.1: Full-Text Search Index Migration
**Objective**: Enable fast text-based search on location names, addresses, and cities.

**Acceptance Criteria**:
- [ ] Migration creates GIN index on locations table using PostgreSQL full-text search
- [ ] Index covers: name, city, address_line_1 (coalesced, concatenated)
- [ ] Index uses 'english' text search configuration
- [ ] Migration is reversible (drop index in down method)
- [ ] EXPLAIN ANALYZE shows index usage for sample queries
- [ ] Text-only queries complete in <50ms

**Dependencies**: None

---

#### Task 1.2: Location Search Scope Implementation
**Objective**: Add model scope for full-text search with relevance ranking.

**Acceptance Criteria**:
- [ ] `scopeSearchByNameOrCategory` method added to Location model
- [ ] Scope uses PostgreSQL `to_tsvector` and `to_tsquery` for matching
- [ ] Returns `text_rank` value using `ts_rank()` for relevance scoring
- [ ] Handles multi-word queries (converts spaces to `&` operator)
- [ ] Integrates seamlessly with existing spatial scopes (chainable)
- [ ] No N+1 query issues when combined with other scopes

**Dependencies**: Task 1.1 (index must exist)

---

#### Task 1.3: Request Validation
**Objective**: Validate all search parameters with comprehensive rules.

**Acceptance Criteria**:
- [ ] LocationSearchRequest form request class created
- [ ] Required parameters validated: `q` (min 2 chars), `lat` (-90 to 90), `lng` (-180 to 180)
- [ ] Optional parameters validated: `radius_km` (0.1 to 50), `include_osm` (boolean), `min_wage_reports` (integer ≥ 0), `per_page` (1 to 500)
- [ ] Clear validation error messages for each rule
- [ ] Custom error messages follow existing conventions
- [ ] Request sanitizes inputs (trim whitespace, etc.)

**Dependencies**: None

---

### Phase 2: WDTP-Only Search (MVP Implementation)

#### Task 2.1: UnifiedLocationResource (WDTP Mode)
**Objective**: Create API resource for consistent response format (WDTP locations initially).

**Acceptance Criteria**:
- [ ] UnifiedLocationResource class created extending JsonResource
- [ ] Transforms Location models to API response format
- [ ] Includes: source, location_id, name, lat, lng, has_wage_data, wage_reports_count, address, organization, distance_meters, relevance_score
- [ ] OSM-specific fields (osm_id, osm_type) set to null for WDTP locations
- [ ] Organization included only when eager loaded
- [ ] Address formatted from location fields
- [ ] Handles missing/null fields gracefully

**Dependencies**: None

---

#### Task 2.2: Relevance Scoring Algorithm
**Objective**: Implement scoring algorithm weighting text match and proximity.

**Acceptance Criteria**:
- [ ] RelevanceScorer service class created
- [ ] `calculate` method accepts location and search parameters
- [ ] Formula: `(text_rank × 0.6) + (proximity_score × 0.4)`
- [ ] Text rank normalized to 0-1 scale (cap at 1.0)
- [ ] Proximity score: `1 - (distance / max_radius)` for locations within radius
- [ ] Proximity score: 0.0 for locations outside radius
- [ ] Returns score rounded to 2 decimal places
- [ ] Algorithm documented in PHPDoc
- [ ] Weights (0.6, 0.4) configurable for future adjustment

**Dependencies**: None

---

#### Task 2.3: Controller Search Action (WDTP-Only)
**Objective**: Implement search endpoint returning WDTP locations only (OSM integration comes later).

**Acceptance Criteria**:
- [ ] `search` method added to LocationController
- [ ] Route registered: `GET /api/v1/locations/search`
- [ ] Queries WDTP locations using search scope + spatial scopes
- [ ] Applies `min_wage_reports` filter if provided
- [ ] Eager loads organization relationship
- [ ] Calculates relevance scores for all results using RelevanceScorer
- [ ] Sorts by relevance_score descending
- [ ] Paginates results (respects per_page parameter)
- [ ] Returns UnifiedLocationResource collection
- [ ] Includes meta object: total, wdtp_count, osm_count (0 for now), search_query, search_type, center, radius_km
- [ ] Detects search_type ("name" vs "category") using simple heuristic
- [ ] WDTP-only queries complete in <200ms

**Dependencies**: Tasks 1.2, 1.3, 2.1, 2.2

---

### Phase 3: OSM Integration (Implementation)

#### Task 3.1: OsmLocation DTO
**Objective**: Create data transfer object for OSM POI results.

**Acceptance Criteria**:
- [ ] OsmLocation class created in DataTransferObjects namespace
- [ ] Properties: osm_id, osm_type, name, latitude, longitude, tags, distance_meters, relevance_score, text_rank
- [ ] Constructor uses PHP 8+ property promotion
- [ ] `formatAddress` method assembles address from OSM tags (housenumber, street, city, state)
- [ ] Returns null if no address components available
- [ ] Default text_rank of 0.5 (moderate relevance for OSM results)
- [ ] DTO is immutable after construction
- [ ] Compatible with UnifiedLocationResource (duck typing)

**Dependencies**: None

---

#### Task 3.2: OverpassService Foundation
**Objective**: Create service for querying local Overpass API server.

**Acceptance Criteria**:
- [ ] OverpassService class created in Services namespace
- [ ] Constructor accepts HTTP client (for testability)
- [ ] Configuration added to services config: base_url, timeout
- [ ] `search` method accepts: query, lat, lng, radiusKm
- [ ] Returns collection of OsmLocation DTOs
- [ ] HTTP timeout set to 10 seconds
- [ ] Throws exception on HTTP errors (4xx, 5xx)
- [ ] No application-level caching (relies on server cache)
- [ ] Service is injectable and mockable for testing

**Dependencies**: Task 3.1 (needs OsmLocation DTO)

---

#### Task 3.3: Overpass Query Builder & Parser
**Objective**: Generate Overpass QL queries and parse JSON responses.

**Acceptance Criteria**:
- [ ] `buildOverpassQuery` method generates valid Overpass QL syntax
- [ ] Detects search type: name-based vs category-based
- [ ] Name search: queries `node["name"~"query"]` and `way["name"~"query"]`
- [ ] Category search: maps common terms to OSM tags (restaurant→amenity=restaurant, etc.)
- [ ] Uses `around` radius filter with lat/lng center
- [ ] Query timeout set to 10 seconds in QL
- [ ] `parseResponse` method extracts elements array
- [ ] Filters elements: must have lat, lon, tags.name
- [ ] Maps elements to OsmLocation DTOs
- [ ] Handles partial address data from OSM tags
- [ ] Category mapping extensible (simple array, can expand)

**Dependencies**: Task 3.2 (part of OverpassService)

---

### Phase 4: Unified Search Integration (Implementation)

#### Task 4.1: Enhance UnifiedLocationResource for OSM
**Objective**: Update resource to handle both WDTP locations and OSM DTOs.

**Acceptance Criteria**:
- [ ] Resource checks instance type (Location vs OsmLocation)
- [ ] WDTP locations: source="wdtp", location_id set, osm fields null
- [ ] OSM locations: source="osm", osm_id/osm_type set, location_id null
- [ ] Organization field null for OSM locations
- [ ] has_wage_data and wage_reports_count zero for OSM locations
- [ ] Both types: distance_meters and relevance_score included
- [ ] Address formatting works for both types
- [ ] No errors when transforming OSM DTOs

**Dependencies**: Tasks 2.1, 3.1 (needs both resource and DTO)

---

#### Task 4.2: Result Merging in Controller
**Objective**: Query both WDTP and OSM, merge, and return unified results.

**Acceptance Criteria**:
- [ ] Controller queries WDTP first (always)
- [ ] If `include_osm=true`, queries OverpassService
- [ ] OSM query wrapped in try/catch (failures don't break response)
- [ ] Calculate distance and relevance for OSM results
- [ ] Merge WDTP + OSM collections
- [ ] Sort merged results by relevance_score descending
- [ ] Paginate merged results (slice after sorting)
- [ ] Meta includes counts: total, wdtp_count, osm_count
- [ ] Meta includes osm_unavailable flag if OSM query failed
- [ ] WDTP-only queries maintain <200ms performance
- [ ] Unified queries complete in <2s

**Dependencies**: Tasks 2.3, 3.2, 4.1 (needs controller, service, enhanced resource)

---

#### Task 4.3: Meta Response Structure
**Objective**: Implement comprehensive meta object for search context.

**Acceptance Criteria**:
- [ ] Meta object includes: total, wdtp_count, osm_count
- [ ] Meta includes: search_query (echoed from request)
- [ ] Meta includes: search_type ("name" or "category")
- [ ] Search type detection: simple heuristic (specific terms = name, generic = category)
- [ ] Meta includes: center {lat, lng} (echoed from request)
- [ ] Meta includes: radius_km (default 10 if not provided)
- [ ] Meta includes: osm_unavailable (boolean, true if OSM failed)
- [ ] All meta fields consistently formatted
- [ ] Meta structure matches API specification exactly

**Dependencies**: Task 4.2 (part of controller response)

---

#### Task 4.4: Error Handling & Graceful Degradation
**Objective**: Handle all OSM failure modes without breaking WDTP results.

**Acceptance Criteria**:
- [ ] OSM timeout (>10s): returns WDTP-only, logs warning, sets osm_unavailable
- [ ] OSM 503: returns WDTP-only, logs warning, sets osm_unavailable
- [ ] OSM connection error: returns WDTP-only, logs error, sets osm_unavailable
- [ ] All OSM exceptions caught and logged (don't propagate)
- [ ] User always receives 200 OK with WDTP results minimum
- [ ] Validation errors still return 422 with clear messages
- [ ] Database errors return 500 with generic message (no internals exposed)
- [ ] Logging includes context: query, error message, stack trace

**Dependencies**: Task 4.2 (part of controller error handling)

---

### Phase 5: Testing (Unit Tests)

#### Task 5.1: Location Search Scope Unit Tests
**Objective**: Test search scope edge cases in isolation.

**Acceptance Criteria**:
- [ ] 15+ test cases covering various query patterns
- [ ] Test: single word queries
- [ ] Test: multi-word queries
- [ ] Test: special characters in queries
- [ ] Test: empty result sets
- [ ] Test: large result sets
- [ ] Test: scope chaining with existing scopes (near, withDistance)
- [ ] Test: text_rank value returned correctly
- [ ] All tests passing

**Dependencies**: Task 1.2 (scope must be implemented)

---

#### Task 5.2: Relevance Scorer Unit Tests
**Objective**: Validate algorithm accuracy with known inputs.

**Acceptance Criteria**:
- [ ] 10+ test cases covering algorithm variations
- [ ] Test: perfect text match at center (score = 1.0)
- [ ] Test: perfect text match at edge (score ≈ 0.6)
- [ ] Test: moderate text match at center (score ≈ 0.7)
- [ ] Test: edge cases (zero distance, max distance)
- [ ] Test: text rank normalization (values >1.0 capped)
- [ ] Test: proximity score calculation
- [ ] Test: weight distribution (60/40 split)
- [ ] All tests passing

**Dependencies**: Task 2.2 (scorer must be implemented)

---

#### Task 5.3: OverpassService Unit Tests
**Objective**: Test service with HTTP mocking.

**Acceptance Criteria**:
- [ ] 12+ test cases for service behavior
- [ ] Test: successful query returns parsed collection
- [ ] Test: timeout throws appropriate exception
- [ ] Test: 503 response throws exception
- [ ] Test: 429 rate limit throws exception
- [ ] Test: malformed response throws exception
- [ ] Test: query builder generates correct QL for name search
- [ ] Test: query builder generates correct QL for category search
- [ ] Test: response parsing with valid OSM JSON
- [ ] Test: response parsing filters incomplete elements
- [ ] All tests use HTTP mocking (no real API calls)
- [ ] All tests passing

**Dependencies**: Task 3.2, 3.3 (service must be implemented)

---

#### Task 5.4: UnifiedLocationResource Unit Tests
**Objective**: Validate resource transformation for both sources.

**Acceptance Criteria**:
- [ ] 10+ test cases for transformation accuracy
- [ ] Test: WDTP location with organization
- [ ] Test: WDTP location without organization (null handling)
- [ ] Test: OSM location with full address tags
- [ ] Test: OSM location with partial address tags
- [ ] Test: source field set correctly (wdtp vs osm)
- [ ] Test: conditional fields (location_id vs osm_id)
- [ ] Test: null safety for all optional fields
- [ ] All tests passing

**Dependencies**: Task 4.1 (enhanced resource must be implemented)

---

#### Task 5.5: OsmLocation DTO Unit Tests
**Objective**: Test DTO creation and address formatting.

**Acceptance Criteria**:
- [ ] 8+ test cases for DTO behavior
- [ ] Test: DTO creation with full tags
- [ ] Test: DTO creation with minimal tags
- [ ] Test: address formatting with full components
- [ ] Test: address formatting with partial components
- [ ] Test: address formatting with no components (returns null)
- [ ] Test: default text_rank value (0.5)
- [ ] All tests passing

**Dependencies**: Task 3.1 (DTO must be implemented)

---

### Phase 6: Testing (Feature Tests)

#### Task 6.1: Location Search Validation Feature Tests
**Objective**: Test all validation rules via API requests.

**Acceptance Criteria**:
- [ ] 12+ test cases for validation scenarios
- [ ] Test: missing required parameter (q, lat, lng) returns 422
- [ ] Test: invalid coordinate ranges return 422
- [ ] Test: invalid radius values return 422
- [ ] Test: per_page limits enforced (max 500)
- [ ] Test: query too short (<2 chars) returns 422
- [ ] Test: valid parameters return 200
- [ ] Test: validation error messages clear and specific
- [ ] All tests passing

**Dependencies**: Task 1.3, 2.3 (validation and endpoint must be implemented)

---

#### Task 6.2: Location Search WDTP-Only Feature Tests
**Objective**: Test WDTP-only search scenarios end-to-end.

**Acceptance Criteria**:
- [ ] 20+ test cases covering various search queries
- [ ] Test: name-based search returns correct results
- [ ] Test: category-based search (future: requires test data setup)
- [ ] Test: spatial filtering (results within radius only)
- [ ] Test: min_wage_reports filtering works
- [ ] Test: pagination works (per_page, page parameters)
- [ ] Test: response format matches specification
- [ ] Test: meta object populated correctly
- [ ] Test: results sorted by relevance_score
- [ ] Test: performance <200ms for typical query
- [ ] All tests passing

**Dependencies**: Task 2.3 (WDTP endpoint must be complete)

---

#### Task 6.3: Unified Location Search Feature Tests
**Objective**: Test mixed WDTP + OSM result scenarios.

**Acceptance Criteria**:
- [ ] 15+ test cases for unified search
- [ ] Test: include_osm=false returns WDTP-only
- [ ] Test: include_osm=true returns merged results
- [ ] Test: results merged and sorted correctly
- [ ] Test: OSM results formatted correctly
- [ ] Test: meta counts accurate (wdtp_count, osm_count)
- [ ] Test: organization field null for OSM results
- [ ] Test: has_wage_data false for OSM results
- [ ] Test: pagination works across merged results
- [ ] All tests use mocked OverpassService
- [ ] All tests passing

**Dependencies**: Task 4.2 (result merging must be implemented)

---

#### Task 6.4: Error Handling Feature Tests
**Objective**: Test all error scenarios via API requests.

**Acceptance Criteria**:
- [ ] 10+ test cases for error handling
- [ ] Test: OSM timeout returns WDTP-only, osm_unavailable=true
- [ ] Test: OSM 503 returns WDTP-only, osm_unavailable=true
- [ ] Test: OSM connection error returns WDTP-only, osm_unavailable=true
- [ ] Test: validation errors return 422
- [ ] Test: all OSM errors logged appropriately
- [ ] Test: response always 200 OK for OSM failures (graceful degradation)
- [ ] All tests use mocked OverpassService for failures
- [ ] All tests passing

**Dependencies**: Task 4.4 (error handling must be implemented)

---

### Phase 7: Testing (Integration & Documentation)

#### Task 7.1: Integration Test Suite
**Objective**: End-to-end tests with real Overpass server (optional in CI).

**Acceptance Criteria**:
- [ ] Integration test class created (marked with @group integration)
- [ ] Tests query real Overpass API at 10.192.50.3:8082
- [ ] Tests verify OSM results returned and formatted correctly
- [ ] Tests confirm Overpass server cache working (repeat query faster)
- [ ] Performance benchmarks: WDTP <200ms, unified <2s
- [ ] Spatial accuracy validated (±25m tolerance)
- [ ] Approximately 30 integration tests covering key scenarios
- [ ] Tests can be skipped in CI if Overpass unavailable
- [ ] Clear instructions for running integration tests locally
- [ ] All tests passing

**Dependencies**: All implementation tasks complete

---

#### Task 7.2: API Documentation (Swagger)
**Objective**: Update OpenAPI specification with new search endpoint.

**Acceptance Criteria**:
- [ ] Swagger annotations added to LocationController@search method
- [ ] All parameters documented: q, lat, lng, radius_km, include_osm, min_wage_reports, per_page
- [ ] Response schema documented: data array and meta object
- [ ] Example request/response included in docs
- [ ] Error responses documented: 422, 500
- [ ] Swagger generation successful: `artisan l5-swagger:generate` runs without errors
- [ ] Documentation accessible via /api/documentation route
- [ ] All field types and constraints clearly specified

**Dependencies**: All implementation tasks complete

---

## Performance Targets

- **WDTP-only queries**: <200ms (95th percentile)
- **Unified queries (WDTP + OSM)**: <2s (95th percentile)
- **Database queries**: <50ms for text search, <100ms for spatial + text combined
- **Spatial accuracy**: ±25m tolerance (existing standard)

---

## Configuration Reference

### Environment Variables
```
OVERPASS_API_URL=http://10.192.50.3:8082/overpass/api/interpreter
OVERPASS_TIMEOUT=10
```

---

## Task Dashboard

**Total Tasks**: 22 (12 implementation + 10 testing)

| Phase | Task Count | Type | Status |
|-------|-----------|------|--------|
| Phase 1: Foundation | 3 | Implementation | Pending |
| Phase 2: WDTP Core | 3 | Implementation | Pending |
| Phase 3: OSM Integration | 3 | Implementation | Pending |
| Phase 4: Unified Search | 4 | Implementation | Pending |
| Phase 5: Unit Testing | 5 | Testing | Pending |
| Phase 6: Feature Testing | 4 | Testing | Pending |
| Phase 7: Integration & Docs | 2 | Testing/Docs | Pending |

---

**Document Status**: Complete - Planning Only
**Last Updated**: 2025-10-01
**Overpass Server**: http://10.192.50.3:8082 (has built-in cache)
**Prepared By**: wdtp-api-architect
