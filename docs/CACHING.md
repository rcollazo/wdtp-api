# WDTP API Caching Strategy

This document outlines the caching strategies, patterns, and management systems used throughout the WDTP API to optimize performance and ensure data consistency.

## Table of Contents

- [Overview](#overview)
- [Cache Version Management](#cache-version-management)
- [Organizations Cache Strategy](#organizations-cache-strategy)
- [Cache Invalidation Patterns](#cache-invalidation-patterns)
- [Performance Considerations](#performance-considerations)
- [Monitoring and Debugging](#monitoring-and-debugging)

---

## Overview

The WDTP API uses a multi-layered caching strategy to optimize database queries and API response times. The system implements:

- **Version-based cache invalidation** for model collections
- **Observer-driven cache management** for automatic invalidation
- **Strategic cache keys** for different data access patterns
- **Laravel's built-in cache system** with configurable backends

**Supported Cache Backends:**
- Redis (recommended for production)
- Database cache (fallback)
- File cache (development)

## Cache Version Management

The WDTP API uses a versioned cache invalidation system that increments version numbers when data changes, ensuring all cached data is automatically invalidated without expensive cache clearing operations.

### Version Key Pattern
```php
// Cache version keys follow the pattern: {model}:ver
'orgs:ver'     // Organizations cache version
'industries:ver' // Industries cache version (existing)
```

### Version Lifecycle
```php
// 1. Initialize version in AppServiceProvider
Cache::add('orgs:ver', 1, 0); // Never expires, starts at version 1

// 2. Increment version when data changes (via observers)
Cache::increment('orgs:ver'); // 1 -> 2 -> 3 -> ...

// 3. Include version in cache keys for automatic invalidation
$cacheKey = "orgs:search:{$term}:v" . Cache::get('orgs:ver');
```

**Benefits:**
- No need for expensive cache clearing operations
- Automatic invalidation of all related cached data
- Version tracking for debugging and monitoring
- Atomic cache operations

## Organizations Cache Strategy

### Cache Key Patterns

The organizations system uses structured cache keys that include the cache version for automatic invalidation:

```php
// Base version key
'orgs:ver' => 1  // Incremented on any organization change

// API endpoint cache keys (implemented)
"orgs:{version}:index:{hash}" => $paginatedResults
"orgs:1:index:a1b2c3d4" => [/* index results with specific params */]

"orgs:{version}:show:{idOrSlug}" => $organization
"orgs:1:show:starbucks" => [/* single organization by slug */]
"orgs:1:show:42" => [/* single organization by ID */]

// Future cache patterns (planned)
"orgs:search:{$term}:v{$version}" => $results
"orgs:search:starbucks:v1" => [/* search results */]
"orgs:search:coffee:v1" => [/* search results */]

// Industry filtering cache
"orgs:industry:{$industry_id}:v{$version}" => $results  
"orgs:industry:5:v1" => [/* food service orgs */]

// Aggregation cache
"orgs:stats:v{$version}" => $statistics
"orgs:stats:v1" => ["total" => 150, "verified" => 89]

// Location-based cache (when Location model exists)
"orgs:near:{$lat}:{$lon}:{$radius}:v{$version}" => $results
```

### Cache Implementation Examples

#### Organizations Controller Caching (Implemented)

**Index Endpoint Caching:**
```php
// OrganizationController@index
$cacheParams = [
    'q' => $search ?: null,
    'industry_id' => $industryId ?: null,
    'industry_slug' => $industrySlug ?: null,
    'verified' => $verified,
    'has_locations' => $hasLocations,
    'per_page' => $perPage,
    'sort' => $requestedSort ?: null,
];
$cacheKey = 'orgs:'.$this->getCacheVersion().':index:'.md5(json_encode($cacheParams));

$organizations = Cache::remember($cacheKey, 300, function () use (...) {
    $query = Organization::defaultFilters();
    // Apply search, filters, sorting
    return $query->paginate($perPage);
});
```

**Show Endpoint Caching:**
```php
// OrganizationController@show  
$cacheKey = 'orgs:'.$this->getCacheVersion().':show:'.$idOrSlug;

$organization = Cache::remember($cacheKey, 300, function () use ($idOrSlug) {
    return Organization::query()
        ->defaultFilters()
        ->with(['primaryIndustry'])
        ->where(function ($query) use ($idOrSlug) {
            if (is_numeric($idOrSlug)) {
                $query->where('id', $idOrSlug);
            } else {
                $query->where('slug', $idOrSlug);
            }
        })
        ->firstOrFail();
});
```

**Cache Version Management:**
```php
private function getCacheVersion(): int
{
    return Cache::get('orgs:ver', 1);
}
```

#### Search Result Caching
```php
// In OrganizationService or controller
public function searchOrganizations(string $term): Collection
{
    $version = Cache::get('orgs:ver', 1);
    $cacheKey = "orgs:search:{$term}:v{$version}";
    
    return Cache::remember($cacheKey, 3600, function () use ($term) {
        return Organization::defaultFilters()
            ->search($term)
            ->with(['primaryIndustry:id,name,slug'])
            ->get();
    });
}
```

#### Industry Filtering Cache
```php
public function getOrganizationsByIndustry(int $industryId): Collection
{
    $version = Cache::get('orgs:ver', 1);
    $cacheKey = "orgs:industry:{$industryId}:v{$version}";
    
    return Cache::remember($cacheKey, 7200, function () use ($industryId) {
        return Organization::defaultFilters()
            ->inIndustry($industryId)
            ->with('primaryIndustry')
            ->orderBy('name')
            ->get();
    });
}
```

#### Statistics Aggregation Cache
```php
public function getOrganizationStats(): array
{
    $version = Cache::get('orgs:ver', 1);
    $cacheKey = "orgs:stats:v{$version}";
    
    return Cache::remember($cacheKey, 1800, function () {
        return [
            'total_organizations' => Organization::count(),
            'active_organizations' => Organization::active()->count(),
            'verified_organizations' => Organization::verified()->count(),
            'organizations_with_locations' => Organization::hasLocations()->count(),
            'by_industry' => Organization::active()
                ->join('industries', 'organizations.primary_industry_id', '=', 'industries.id')
                ->groupBy('industries.name')
                ->selectRaw('industries.name, count(*) as count')
                ->pluck('count', 'name')
                ->toArray(),
        ];
    });
}
```

### Spatial Query Caching (Future Implementation)

When Location model is implemented, spatial queries will be cached with geographic precision:

```php
public function getNearbyOrganizations(float $lat, float $lon, int $radiusKm): Collection
{
    $version = Cache::get('orgs:ver', 1);
    // Round coordinates to reduce cache key variations
    $roundedLat = round($lat, 3); // ~111m precision
    $roundedLon = round($lon, 3);
    $cacheKey = "orgs:near:{$roundedLat}:{$roundedLon}:{$radiusKm}:v{$version}";
    
    return Cache::remember($cacheKey, 1800, function () use ($lat, $lon, $radiusKm) {
        return Organization::defaultFilters()
            ->whereHas('locations', function ($query) use ($lat, $lon, $radiusKm) {
                $query->near($lat, $lon, $radiusKm);
            })
            ->with(['locations' => function ($query) use ($lat, $lon) {
                $query->withDistance($lat, $lon);
            }])
            ->get();
    });
}
```

## Cache Invalidation Patterns

### Observer-Driven Invalidation

The `OrganizationObserver` automatically increments the cache version when any organization data changes:

```php
class OrganizationObserver
{
    public function saved(Organization $organization): void
    {
        Cache::increment('orgs:ver');
    }
    
    public function deleted(Organization $organization): void  
    {
        Cache::increment('orgs:ver');
    }
}
```

**Triggering Events:**
- Organization created, updated, or deleted
- Website URL changes (triggers domain normalization)
- Status or verification changes
- Industry assignment changes

### Manual Cache Management

For specific use cases, manual cache invalidation may be needed:

```php
// Clear specific cache entries
Cache::forget("orgs:search:starbucks:v1");

// Force version increment (invalidates all organization caches)
Cache::increment('orgs:ver');

// Get current version for debugging
$currentVersion = Cache::get('orgs:ver', 1);

// Check if cache key exists
$exists = Cache::has("orgs:stats:v{$currentVersion}");
```

### Cache Warming Strategies

For frequently accessed data, implement cache warming:

```php
// Artisan command: php artisan cache:warm-organizations
class WarmOrganizationsCache extends Command
{
    public function handle()
    {
        $version = Cache::get('orgs:ver', 1);
        
        // Pre-cache popular searches
        $popularTerms = ['starbucks', 'mcdonalds', 'walmart', 'amazon'];
        foreach ($popularTerms as $term) {
            $cacheKey = "orgs:search:{$term}:v{$version}";
            Cache::remember($cacheKey, 3600, function () use ($term) {
                return Organization::defaultFilters()->search($term)->get();
            });
        }
        
        // Pre-cache industry listings
        $industries = Industry::active()->pluck('id');
        foreach ($industries as $industryId) {
            $cacheKey = "orgs:industry:{$industryId}:v{$version}";
            Cache::remember($cacheKey, 7200, function () use ($industryId) {
                return Organization::defaultFilters()->inIndustry($industryId)->get();
            });
        }
        
        // Pre-cache statistics
        $cacheKey = "orgs:stats:v{$version}";
        Cache::remember($cacheKey, 1800, function () {
            return $this->generateStatistics();
        });
    }
}
```

## Performance Considerations

### Cache TTL (Time-To-Live) Guidelines

Different data types have different caching durations based on volatility:

```php
// High volatility - short TTL
$searchResults = Cache::remember($key, 1800, $callback);  // 30 minutes

// Medium volatility - medium TTL  
$industryOrgs = Cache::remember($key, 7200, $callback);   // 2 hours

// Low volatility - long TTL
$statistics = Cache::remember($key, 14400, $callback);    // 4 hours

// Very stable data - very long TTL
$industryList = Cache::remember($key, 86400, $callback);  // 24 hours
```

### Memory Usage Optimization

```php
// Use select() to limit cached data size
Organization::defaultFilters()
    ->select(['id', 'name', 'slug', 'domain', 'locations_count'])
    ->get();

// Limit relationship data in cache
Organization::with(['primaryIndustry:id,name,slug'])
    ->get();

// Use pagination for large result sets
Cache::remember($key, $ttl, function () {
    return Organization::defaultFilters()->simplePaginate(50);
});
```

### Cache Hit Rate Monitoring

```php
// Track cache effectiveness
class CacheMetricsService
{
    public function trackCacheHit(string $key): void
    {
        Cache::increment("metrics:cache:hits:{$key}");
    }
    
    public function trackCacheMiss(string $key): void
    {
        Cache::increment("metrics:cache:misses:{$key}");  
    }
    
    public function getCacheStats(): array
    {
        return [
            'orgs_search_hits' => Cache::get('metrics:cache:hits:orgs:search', 0),
            'orgs_search_misses' => Cache::get('metrics:cache:misses:orgs:search', 0),
            'current_version' => Cache::get('orgs:ver', 1),
        ];
    }
}
```

## Monitoring and Debugging

### Cache Health Checks

```php
// Add to health check endpoints
class CacheHealthCheck
{
    public function check(): array
    {
        $orgsVersion = Cache::get('orgs:ver');
        $industriesVersion = Cache::get('industries:ver');
        
        return [
            'cache_backend' => config('cache.default'),
            'organizations_version' => $orgsVersion,
            'industries_version' => $industriesVersion,
            'version_consistency' => $this->checkVersionConsistency(),
        ];
    }
    
    private function checkVersionConsistency(): bool
    {
        // Verify cache versions are incrementing properly
        $version = Cache::get('orgs:ver', 1);
        return is_numeric($version) && $version > 0;
    }
}
```

### Debug Commands

Useful artisan commands for cache debugging:

```bash
# Check current cache versions
./vendor/bin/sail artisan tinker
Cache::get('orgs:ver')
Cache::get('industries:ver')

# Manual version increment for testing
Cache::increment('orgs:ver')

# Clear all organization caches
Cache::increment('orgs:ver')  # Preferred method

# Alternative: Clear specific patterns (less efficient)
Cache::flush() # Nuclear option - clears everything

# View cache store info
php artisan cache:table
```

### Logging Cache Operations

```php
// Add to OrganizationObserver for debugging
class OrganizationObserver
{
    public function saved(Organization $organization): void
    {
        $oldVersion = Cache::get('orgs:ver', 1);
        Cache::increment('orgs:ver');
        $newVersion = Cache::get('orgs:ver');
        
        Log::debug('Organization cache invalidated', [
            'organization_id' => $organization->id,
            'old_version' => $oldVersion,
            'new_version' => $newVersion,
            'trigger' => 'organization_saved'
        ]);
    }
}
```

### Best Practices Summary

1. **Always use versioned cache keys** for model collections
2. **Implement observer-driven invalidation** for automatic cache management  
3. **Use appropriate TTL values** based on data volatility
4. **Limit cached data size** with select() and relationship constraints
5. **Monitor cache hit rates** to optimize caching strategies
6. **Implement cache warming** for frequently accessed data
7. **Use geographic rounding** for spatial query caching
8. **Log cache operations** in development for debugging
9. **Test cache invalidation** thoroughly in your test suite
10. **Document cache dependencies** for maintenance

This caching strategy ensures optimal performance while maintaining data consistency across the WDTP API.