# Performance Optimization Documentation

This document outlines performance optimization strategies, monitoring guidelines, and architectural decisions for the WDTP API.

## Counter Management Strategy

### Denormalized Counter Fields

**Purpose**: Provide fast access to aggregate counts without expensive COUNT() queries

**Implementation**:
- `organizations.wage_reports_count`: Count of approved wage reports per organization
- `locations.wage_reports_count`: Count of approved wage reports per location

**Maintenance Strategy**:
```php
// Atomic counter updates with underflow protection
DB::transaction(function () use ($wageReport) {
    // Increment location counter
    DB::table('locations')
        ->where('id', $wageReport->location_id)
        ->increment('wage_reports_count');
        
    // Increment organization counter if present
    if ($wageReport->organization_id) {
        DB::table('organizations')
            ->where('id', $wageReport->organization_id)
            ->increment('wage_reports_count');
    }
});
```

**Benefits**:
- O(1) access to aggregate counts
- Eliminates expensive JOIN COUNT queries on large datasets
- Supports efficient pagination and sorting
- Reduces load on primary wage_reports table

**Consistency Guarantees**:
- Database transactions ensure atomicity
- Observer pattern provides event-driven updates
- Migration initializes counters from existing data
- Underflow protection prevents negative counts

### Observer Performance Optimization

**MAD Algorithm Performance**:
```sql
-- Optimized MAD calculation using window functions
WITH wage_stats AS (
    SELECT 
        normalized_hourly_cents,
        PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY normalized_hourly_cents) OVER () as median_cents
    FROM wage_reports 
    WHERE location_id = ? AND status = 'approved'
)
SELECT median_cents, AVG(ABS(normalized_hourly_cents - median_cents)) as mad_cents
FROM wage_stats;
```

**Caching Strategy**:
- Version-based cache invalidation (wages:ver, orgs:ver, locations:ver)
- Cache warming for frequently accessed statistics
- Progressive fallback: location → organization → global bounds

**Monitoring Metrics**:
- Observer event timing (target: <100ms total)
- Counter consistency checks (scheduled task)
- Cache hit ratios for statistical queries
- XP award processing latency

## Spatial Query Performance

### PostGIS Optimization

**Index Strategy**:
```sql
-- GiST spatial index for optimal distance queries
CREATE INDEX locations_point_gist_idx ON locations USING GIST (point);

-- Composite index for filtered spatial queries
CREATE INDEX idx_locations_active_point ON locations USING GIST (point) 
WHERE is_active = true;

-- Support index for wage report spatial queries
CREATE INDEX idx_wage_reports_location_status ON wage_reports (location_id, status);
```

**Query Optimization Patterns**:
```php
// Efficient radius search with distance calculation
Location::whereRaw(
    'ST_DWithin(point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
    [$longitude, $latitude, $radiusMeters]
)
->selectRaw(
    'locations.*, ST_Distance(point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_meters',
    [$longitude, $latitude]
)
->orderByRaw('distance_meters')
->limit(50)
->get();
```

**Performance Targets**:
- Spatial queries complete within 200ms (tested requirement)
- Distance calculations accurate to ±25m tolerance
- Support up to 10,000 locations with sub-second response times
- GiST index utilization verified in query execution plans

### Spatial Query Caching

**Cache Key Strategy**:
```php
// Location-based cache keys with coordinate precision
$cacheKey = "locations:near:{$lat}:{$lng}:{$radiusKm}:ver:" . Cache::get('locations:ver', 0);

// Wage reports spatial cache with filters
$cacheKey = "wages:spatial:{$lat}:{$lng}:{$radius}:{$filters_hash}:ver:" . Cache::get('wages:ver', 0);
```

**Cache TTL Strategy**:
- Spatial results: 300 seconds (5 minutes)
- Statistical aggregations: 600 seconds (10 minutes) 
- Location metadata: 1800 seconds (30 minutes)
- Distance calculations: No caching (fast enough)

## Database Performance

### Index Optimization Strategy

**Primary Indexes**:
```sql
-- Organizations table
CREATE INDEX idx_organizations_name ON organizations (name);
CREATE INDEX idx_organizations_domain_lower ON organizations (lower(domain));
CREATE INDEX idx_organizations_status_active ON organizations (status, is_active);
CREATE INDEX idx_organizations_industry_verified ON organizations (primary_industry_id, verification_status);

-- Locations table  
CREATE INDEX idx_locations_org_active ON locations (organization_id, is_active);
CREATE INDEX idx_locations_city_state ON locations (city, state_province);
CREATE INDEX idx_locations_coordinates ON locations (latitude, longitude);

-- Wage reports table
CREATE INDEX idx_wage_reports_location_status ON wage_reports (location_id, status);
CREATE INDEX idx_wage_reports_org_status ON wage_reports (organization_id, status);
CREATE INDEX idx_wage_reports_normalized_wage ON wage_reports (normalized_hourly_cents);
CREATE INDEX idx_wage_reports_effective_date ON wage_reports (effective_date);
CREATE INDEX idx_wage_reports_job_title ON wage_reports (job_title);
```

**Composite Index Strategy**:
- Primary filter + secondary filter combinations
- Status + timestamp for time-series queries
- Foreign key + status for counter maintenance
- Search field + relevance ordering

### Query Performance Guidelines

**Efficient Query Patterns**:
```php
// Use indexes effectively with consistent WHERE clause ordering
WageReport::where('location_id', $locationId)  // Indexed field first
    ->where('status', 'approved')              // Secondary index field
    ->orderBy('effective_date', 'desc')        // Indexed ordering field
    ->limit(25)
    ->get();

// Avoid N+1 queries with eager loading
Organization::with(['primaryIndustry', 'locations'])
    ->where('status', 'active')
    ->paginate(25);
```

**Query Optimization Checklist**:
- ✅ Use EXPLAIN ANALYZE to verify index usage
- ✅ Avoid SELECT * when only specific fields needed
- ✅ Use pagination limits to prevent large result sets
- ✅ Eager load relationships to prevent N+1 problems
- ✅ Use database-level constraints for data integrity

## Caching Architecture

### Version-Based Cache Invalidation

**Cache Version Keys**:
```php
// Global version keys for cache invalidation
Cache::get('wages:ver', 0);      // Wage report data version
Cache::get('orgs:ver', 0);       // Organization data version
Cache::get('locations:ver', 0);  // Location data version
Cache::get('industries:ver', 0); // Industry data version
```

**Cache Key Patterns**:
```php
// Versioned cache keys prevent stale data
$wagesCacheKey = "wages:approved:{$filters_hash}:ver:" . Cache::get('wages:ver', 0);
$orgCacheKey = "org:show:{$idOrSlug}:ver:" . Cache::get('orgs:ver', 0);
$locationCacheKey = "location:near:{$lat}:{$lng}:{$radius}:ver:" . Cache::get('locations:ver', 0);
```

**Observer-Driven Invalidation**:
```php
// Automatic version bumping via model observers
class WageReportObserver 
{
    public function created(WageReport $wageReport): void
    {
        Cache::increment('wages:ver');
        Cache::increment('orgs:ver');       // Organization counters changed
        Cache::increment('locations:ver');  // Location counters changed
    }
}
```

### Cache Layer Strategy

**L1 Cache (Application)**:
- Laravel cache facade with Redis backend
- TTL-based expiration with version invalidation
- Serialized model collections and API responses

**L2 Cache (Database)**:
- PostgreSQL shared buffer cache
- Query plan cache for repeated queries
- Index buffer cache for spatial operations

**L3 Cache (CDN/Proxy)**:
- API response caching at edge locations
- Static asset delivery (future implementation)
- Geographic distribution for spatial queries

## Monitoring & Metrics

### Performance Monitoring Dashboard

**Key Metrics to Track**:

**Database Performance**:
- Query execution time (p95, p99 percentiles)
- Index hit ratio (target: >95%)
- Connection pool utilization
- Lock wait times and deadlocks

**Cache Performance**:
- Cache hit ratio by cache type (target: >90%)
- Cache invalidation frequency
- Memory usage and eviction rates
- Network latency to cache backend

**Observer Performance**:
- Event processing time by observer method
- Transaction rollback rates
- XP award processing latency
- Counter consistency validation

**Spatial Query Performance**:
- PostGIS query execution time distribution
- GiST index utilization rates
- Distance calculation accuracy validation
- Geographic query patterns and hotspots

### Alerting Thresholds

**Critical Alerts**:
- Database query time >500ms
- Cache hit ratio <80%
- Observer event processing >100ms
- Spatial query accuracy >100m deviation

**Warning Alerts**:
- Database connection pool >75% utilized
- Cache memory usage >85%
- Counter consistency errors detected
- Unusual MAD calculation patterns

### Performance Testing Strategy

**Load Testing Scenarios**:
- Concurrent wage report submissions
- High-frequency spatial queries
- Bulk organization/location creation
- Cache invalidation storms

**Benchmarking Targets**:
- 100 concurrent users: <200ms response time
- 1000 wage reports: <1s batch processing
- 10,000 locations: <500ms spatial query
- 95% cache hit ratio under normal load

## Optimization Roadmap

### Phase 1: Current Implementation
- ✅ Denormalized counters with atomic updates
- ✅ PostGIS spatial indexing and optimization
- ✅ Version-based cache invalidation
- ✅ Observer pattern for business logic

### Phase 2: Scale Optimization (Future)
- 🔄 Read replicas for query distribution
- 🔄 Connection pooling optimization
- 🔄 Database partitioning for wage_reports table
- 🔄 Redis Cluster for distributed caching

### Phase 3: Advanced Performance (Future)
- 📋 Full-text search with Elasticsearch
- 📋 Event sourcing for audit trails
- 📋 CQRS pattern for read/write separation
- 📋 GraphQL for efficient data fetching

**Legend**: ✅ Complete, 🔄 In Progress, 📋 Planned

## Troubleshooting Common Performance Issues

### Slow Spatial Queries

**Symptoms**: PostGIS queries taking >1s
**Diagnosis**: 
```sql
EXPLAIN ANALYZE SELECT * FROM locations 
WHERE ST_DWithin(point, ST_SetSRID(ST_MakePoint(-74.006, 40.7128), 4326)::geography, 5000);
```
**Solutions**:
- Verify GiST index usage in query plan
- Check coordinate parameter order (longitude, latitude)
- Ensure geography type consistency
- Add composite spatial indexes for filtered queries

### Counter Inconsistencies  

**Symptoms**: Denormalized counters don't match COUNT(*) queries
**Diagnosis**:
```php
// Validation query to check consistency
$actualCount = WageReport::where('location_id', $locationId)
    ->where('status', 'approved')
    ->count();
$cachedCount = Location::find($locationId)->wage_reports_count;
```
**Solutions**:
- Run counter recalculation migration
- Check observer registration in AppServiceProvider
- Verify transaction boundaries in counter updates
- Add scheduled task for periodic consistency checks

### Cache Invalidation Issues

**Symptoms**: Stale data returned despite model changes
**Diagnosis**: Check cache version increment in observer events
**Solutions**:
- Verify observer is registered and firing
- Check Redis connectivity and memory limits
- Validate cache key generation consistency
- Monitor cache version increments in logs

This performance documentation provides comprehensive guidance for maintaining optimal API performance while supporting the WDTP application's growth and scaling requirements.