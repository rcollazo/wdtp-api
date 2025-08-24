# WDTP API Routes Documentation

Complete route definitions and patterns for the WDTP API endpoints.

## Table of Contents

- [Route Structure](#route-structure)
- [Master Route Table](#master-route-table)
- [Industries Routes](#industries-routes)
- [Organizations Routes](#organizations-routes)
- [Authentication Routes](#authentication-routes)
- [Health Check Routes](#health-check-routes)
- [Route Patterns](#route-patterns)

---

## Route Structure

All API routes use the `/api/v1` prefix and follow RESTful conventions where applicable.

**Base Configuration:**
- **Prefix**: `/api/v1`
- **Middleware**: `api` (includes throttling, JSON response formatting)
- **Route Caching**: Enabled in production
- **Route Model Binding**: Used for resource resolution

### Route Registration

Routes are registered in `/routes/api.php` using explicit route definitions for maximum control:

```php
Route::prefix('v1')->group(function () {
    Route::get('/healthz', [HealthCheckController::class, 'basic']);
    Route::get('/healthz/deep', [HealthCheckController::class, 'deep']);

    // Authentication routes
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    });

    // Industries endpoints
    Route::get('industries', [IndustryController::class, 'index']);
    Route::get('industries/autocomplete', [IndustryController::class, 'autocomplete']);
    Route::get('industries/{idOrSlug}', [IndustryController::class, 'show']);

    // Organizations endpoints
    Route::get('organizations', [OrganizationController::class, 'index']);
    Route::get('organizations/autocomplete', [OrganizationController::class, 'autocomplete']);
    Route::get('organizations/{idOrSlug}', [OrganizationController::class, 'show']);
});
```

### Route Order Importance

**Critical Route Ordering:**
The autocomplete routes MUST be registered before the `{idOrSlug}` routes to prevent conflicts:

```php
// ✅ CORRECT: Specific routes first
Route::get('organizations/autocomplete', [OrganizationController::class, 'autocomplete']);
Route::get('organizations/{idOrSlug}', [OrganizationController::class, 'show']);

// ❌ INCORRECT: Would match 'autocomplete' as an idOrSlug parameter  
Route::get('organizations/{idOrSlug}', [OrganizationController::class, 'show']);
Route::get('organizations/autocomplete', [OrganizationController::class, 'autocomplete']);
```

---

## Industries Routes

### Route Definitions

| Method | Path | Controller@Action | Name |
|--------|------|-------------------|------|
| `GET` | `/api/v1/industries` | `IndustryController@index` | `industries.index` |
| `GET` | `/api/v1/industries/autocomplete` | `IndustryController@autocomplete` | `industries.autocomplete` |
| `GET` | `/api/v1/industries/{idOrSlug}` | `IndustryController@show` | `industries.show` |

### Route Parameters

**`{idOrSlug}` Parameter:**
- Accepts integer ID (e.g., `5`) or string slug (e.g., `food-service`)
- Uses implicit route model binding with `resolveRouteBinding()` method
- Automatically resolves to Industry model instance

### Query Parameters

**Index Endpoint (`/api/v1/industries`):**
- `tree` (boolean): Return nested tree structure
- `q` (string, min:2): Search term for filtering
- `per_page` (integer, 1-100): Pagination size

**Autocomplete Endpoint (`/api/v1/industries/autocomplete`):**
- `q` (required, string, min:2): Search term

### Example Routes

```bash
# Get all industries as flat list
GET /api/v1/industries

# Get industries as hierarchical tree
GET /api/v1/industries?tree=true

# Get specific industry by ID
GET /api/v1/industries/5

# Get specific industry by slug  
GET /api/v1/industries/food-service

# Search industries for autocomplete
GET /api/v1/industries/autocomplete?q=rest
```

---

## Organizations Routes

### Route Definitions

| Method | Path | Controller@Action | Name |
|--------|------|-------------------|------|
| `GET` | `/api/v1/organizations` | `OrganizationController@index` | `organizations.index` |
| `GET` | `/api/v1/organizations/autocomplete` | `OrganizationController@autocomplete` | `organizations.autocomplete` |
| `GET` | `/api/v1/organizations/{idOrSlug}` | `OrganizationController@show` | `organizations.show` |

### Route Parameters

**`{idOrSlug}` Parameter:**
- Accepts integer ID (e.g., `42`) or string slug (e.g., `starbucks`)
- Resolved in controller using conditional where clause:
  ```php
  if (is_numeric($idOrSlug)) {
      $query->where('id', $idOrSlug);
  } else {
      $query->where('slug', $idOrSlug);
  }
  ```

### Rate Limiting
Organization routes have **no additional rate limiting** beyond the standard API throttling:
- Follow default Laravel API rate limits (60 requests/minute for authenticated users)
- No custom throttling middleware applied to organization endpoints
- Cache-first strategy reduces database load and improves performance

### Query Parameters

**Index Endpoint (`/api/v1/organizations`):**
- `q` (string, min:2): Search term for name, legal_name, or domain
- `industry_id` (integer): Filter by industry ID
- `industry_slug` (string): Filter by industry slug
- `verified` (boolean): Filter by verification status
- `has_locations` (boolean): Filter organizations with/without locations
- `per_page` (integer, 1-100, default:25): Items per page
- `sort` (enum): Sort order options

**Sort Options:**
- `relevance`: Search relevance (only with `q` parameter)
- `name`: Alphabetical by name (default)
- `locations`: By location count (descending)
- `wage_reports`: By wage report count (descending)
- `updated`: By last update time (descending)

**Autocomplete Endpoint (`/api/v1/organizations/autocomplete`):**
- `q` (required, string, min:2): Search term for organization name
- `limit` (integer, 1-50, default:10): Maximum number of results

### Example Routes

```bash
# Get all organizations (paginated)
GET /api/v1/organizations

# Search organizations
GET /api/v1/organizations?q=starbucks

# Filter by industry
GET /api/v1/organizations?industry_slug=coffee-shop

# Filter verified organizations with locations  
GET /api/v1/organizations?verified=true&has_locations=true

# Sort by location count with pagination
GET /api/v1/organizations?sort=locations&per_page=50

# Get specific organization by ID
GET /api/v1/organizations/42

# Get specific organization by slug
GET /api/v1/organizations/starbucks

# Complex filtering example
GET /api/v1/organizations?q=coffee&industry_id=5&verified=true&sort=wage_reports&per_page=20

# Autocomplete search
GET /api/v1/organizations/autocomplete?q=starb

# Autocomplete with limit
GET /api/v1/organizations/autocomplete?q=coffee&limit=5
```

---

## Authentication Routes

### Route Definitions

| Method | Path | Controller@Action | Name |
|--------|------|-------------------|------|
| `POST` | `/api/v1/auth/register` | `AuthController@register` | `auth.register` |
| `POST` | `/api/v1/auth/login` | `AuthController@login` | `auth.login` |
| `POST` | `/api/v1/auth/logout` | `AuthController@logout` | `auth.logout` |
| `GET` | `/api/v1/auth/me` | `AuthController@me` | `auth.me` |

### Authentication Middleware

```php
// Public routes (no authentication required)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});
```

---

## Route Patterns

### RESTful Conventions

**Standard Resource Routes:**
- `GET /resource` - List collection (index)
- `GET /resource/{id}` - Show specific resource
- `POST /resource` - Create new resource  
- `PUT/PATCH /resource/{id}` - Update resource
- `DELETE /resource/{id}` - Delete resource

**Current Implementation:**
- Industries: Full read-only API (index, show, autocomplete)
- Organizations: Read-only API (index, show) - creation/update TBD

### Custom Route Extensions

**Autocomplete Pattern:**
```php
// Custom route for typeahead/search functionality
Route::get('/{resource}/autocomplete', [Controller::class, 'autocomplete'])
    ->name('{resource}.autocomplete');
```

**Tree Structure Pattern:**
```php
// Query parameter for hierarchical data
GET /api/v1/industries?tree=true
```

### Route Model Binding Patterns

**Simple Binding:**
```php
Route::get('/industries/{industry}', [IndustryController::class, 'show']);
// Automatically injects Industry model
public function show(Industry $industry) { }
```

**Custom Binding Resolution:**
```php
// In Industry model
public function resolveRouteBinding($value, $field = null)
{
    return $this->where('id', $value)
               ->orWhere('slug', $value)
               ->firstOrFail();
}
```

**Manual Resolution (Organizations):**
```php
// In controller when custom logic needed
public function show(string $idOrSlug)
{
    $organization = Organization::query()
        ->defaultFilters()
        ->where(function ($query) use ($idOrSlug) {
            if (is_numeric($idOrSlug)) {
                $query->where('id', $idOrSlug);
            } else {
                $query->where('slug', $idOrSlug);
            }
        })
        ->firstOrFail();
}
```

### URL Pattern Examples

**Valid Industry URLs:**
- `/api/v1/industries/5` (by ID)
- `/api/v1/industries/food-service` (by slug)
- `/api/v1/industries/coffee-shop` (by slug)

**Valid Organization URLs:**
- `/api/v1/organizations/42` (by ID)
- `/api/v1/organizations/starbucks` (by slug)
- `/api/v1/organizations/mcdonalds-corporation` (by slug)

**Invalid URLs:**
- `/api/v1/industries/0` (invalid ID)
- `/api/v1/organizations/invalid-slug` (non-existent)
- `/api/v1/industries/food service` (spaces not allowed)

### Route Caching

**Production Optimization:**
```bash
# Cache routes for performance
./vendor/bin/sail artisan route:cache

# Clear route cache during development
./vendor/bin/sail artisan route:clear

# List all registered routes
./vendor/bin/sail artisan route:list
```

**Cache Considerations:**
- Routes cached in production for performance
- Closures not supported in cached routes (use controller references)
- Route model binding works with cached routes
- Custom route patterns must be cacheable

### Route Testing

**Test Route Registration:**
```php
// Feature test example
public function test_organizations_routes_exist(): void
{
    $response = $this->getJson('/api/v1/organizations');
    $response->assertOk();
    
    $response = $this->getJson('/api/v1/organizations/1');
    $response->assertStatus(404); // Or 200 with data
}
```

**Route Parameter Testing:**
```php
public function test_organization_slug_resolution(): void
{
    $org = Organization::factory()->create(['slug' => 'test-org']);
    
    $response = $this->getJson("/api/v1/organizations/{$org->slug}");
    $response->assertOk()
            ->assertJsonFragment(['slug' => 'test-org']);
}
```

---

## Future Route Planning

### Planned Organization Routes

```php
// Full CRUD operations (requires authentication)
POST   /api/v1/organizations              # Create organization
PUT    /api/v1/organizations/{idOrSlug}   # Update organization  
DELETE /api/v1/organizations/{idOrSlug}   # Delete organization

// Nested resource routes
GET    /api/v1/organizations/{id}/locations      # Organization locations
GET    /api/v1/organizations/{id}/wage-reports   # Organization wage reports
```

### Planned Location Routes

```php
// Location CRUD with spatial queries
GET    /api/v1/locations                   # List with spatial filtering
POST   /api/v1/locations                   # Create location
GET    /api/v1/locations/{id}              # Show location
PUT    /api/v1/locations/{id}              # Update location
DELETE /api/v1/locations/{id}              # Delete location

// Spatial query routes
GET    /api/v1/locations/near              # Locations near coordinates
```

### Planned Wage Report Routes

```php
// Wage report lifecycle
GET    /api/v1/wage-reports                # List approved reports
POST   /api/v1/wage-reports                # Submit new report  
GET    /api/v1/wage-reports/{id}           # Show report
PATCH  /api/v1/wage-reports/{id}/approve   # Moderate report
PATCH  /api/v1/wage-reports/{id}/reject    # Reject report
POST   /api/v1/wage-reports/{id}/vote      # Vote on report
POST   /api/v1/wage-reports/{id}/flag      # Flag report
```

This route documentation provides a complete reference for all current API endpoints and establishes patterns for future development.