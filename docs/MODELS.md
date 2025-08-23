# WDTP API Models Documentation

This document provides comprehensive documentation for all Eloquent models in the WDTP API, including their relationships, scopes, behaviors, and usage patterns.

## Table of Contents

- [Organization Model](#organization-model)
  - [Overview](#overview)
  - [Model Relationships](#model-relationships)
  - [Query Scopes](#query-scopes)
  - [Attributes & Accessors](#attributes--accessors)
  - [Route Model Binding](#route-model-binding)
  - [Observer Behavior](#observer-behavior)
  - [Usage Examples](#usage-examples)

---

## Organization Model

### Overview

The `Organization` model represents business entities in the WDTP system (e.g., McDonald's, Walmart, Starbucks). Organizations belong to industries and have multiple physical locations where wage reports are collected.

**Key Features:**
- Industry categorization with hierarchical support
- Domain normalization from website URLs
- Slug-based and ID-based route binding  
- Comprehensive search with relevance weighting
- Cache invalidation management
- Status and verification workflow support

**Database Table:** `organizations`

**Fillable Attributes:**
```php
[
    'name', 'slug', 'legal_name', 'website_url', 'domain',
    'description', 'logo_url', 'primary_industry_id', 
    'status', 'verification_status', 'created_by', 'verified_by',
    'verified_at', 'locations_count', 'wage_reports_count',
    'is_active', 'visible_in_ui'
]
```

### Model Relationships

#### Primary Industry
```php
public function primaryIndustry(): BelongsTo
```

**Usage:**
```php
$organization = Organization::with('primaryIndustry')->first();
echo $organization->primaryIndustry->name; // "Food Service"

// Eager loading for performance
$orgs = Organization::with('primaryIndustry')->get();
```

#### Locations
```php  
public function locations(): HasMany
```

**Usage:**
```php
$organization = Organization::find(1);
$locations = $organization->locations; // All locations for this org

// With constraints
$activeLocations = $organization->locations()->where('is_active', true)->get();

// Count locations efficiently
$locationCount = $organization->locations()->count();
```

**Note:** Location model implementation is pending. Currently uses string reference to avoid class not found errors.

#### Created By / Verified By
```php
public function createdBy(): BelongsTo
public function verifiedBy(): BelongsTo
```

**Usage:**
```php
$organization = Organization::with(['createdBy', 'verifiedBy'])->first();
echo $organization->createdBy->name; // User who created the org
echo $organization->verifiedBy->name ?? 'Not verified'; // User who verified (if any)
```

### Query Scopes

#### Basic Status Scopes

**Active Organizations:**
```php
public function scopeActive(Builder $query): Builder

// Usage
Organization::active()->get(); // Only is_active = true
Organization::active()->count(); // Count active orgs
```

**Visible Organizations:**
```php
public function scopeVisible(Builder $query): Builder

// Usage  
Organization::visible()->paginate(20); // Only visible_in_ui = true
```

**Approved Organizations:**
```php
public function scopeApproved(Builder $query): Builder

// Usage
Organization::approved()->get(); // Only status = 'active'
```

**Verified Organizations:**
```php
public function scopeVerified(Builder $query): Builder

// Usage
Organization::verified()->get(); // Only verification_status = 'verified' 
```

#### Search and Filtering Scopes

**Search by Name/Domain/Legal Name:**
```php
public function scopeSearch(Builder $query, string $term): Builder
```

Features:
- Case-insensitive search using ILIKE
- Relevance-weighted results (name > domain > legal_name)
- Automatic ordering by match quality

**Usage:**
```php
// Search for organizations
Organization::search('starbucks')->get();
Organization::search('mcdonalds.com')->first(); // Search by domain
Organization::search('walmart stores inc')->paginate(10); // Legal name search

// Combined with other scopes
Organization::active()->search('coffee')->verified()->get();
```

**Filter by Industry:**
```php
public function scopeInIndustry(Builder $query, string|int $industry): Builder
```

Supports both industry ID and slug:

```php
// By industry ID
Organization::inIndustry(5)->get();

// By industry slug  
Organization::inIndustry('food-service')->get();
Organization::inIndustry('retail')->approved()->paginate(20);

// Combined queries
Organization::inIndustry('restaurants')
    ->active()
    ->verified()
    ->search('pizza')
    ->orderBy('name')
    ->get();
```

**Organizations with Locations:**
```php
public function scopeHasLocations(Builder $query): Builder

// Usage
Organization::hasLocations()->get(); // Only orgs with locations_count > 0
Organization::hasLocations()->verified()->count(); // Verified orgs with locations
```

**Find by Slug:**
```php
public function scopeBySlug(Builder $query, string $slug): Builder

// Usage
Organization::bySlug('mcdonalds')->first();
Organization::bySlug('starbucks-coffee')->active()->first();
```

**Default Filters (Common Combination):**
```php
public function scopeDefaultFilters(Builder $query): Builder
```

Combines: active + visible + approved status

```php
// Equivalent to: active()->visible()->approved()
Organization::defaultFilters()->get();
Organization::defaultFilters()->search('coffee')->paginate(10);
```

### Attributes & Accessors

#### Display Name Accessor
```php
public function getDisplayNameAttribute(): string
```

**Logic:** Returns `name` if available, falls back to `legal_name`, then empty string.

**Usage:**
```php
$org = new Organization([
    'name' => 'Starbucks',
    'legal_name' => 'Starbucks Corporation'
]);
echo $org->display_name; // "Starbucks"

$org = new Organization([
    'name' => '',
    'legal_name' => 'McDonald\'s Corporation'  
]);
echo $org->display_name; // "McDonald's Corporation"

$org = new Organization([]);
echo $org->display_name; // ""
```

#### Casts
```php
protected function casts(): array
{
    return [
        'is_active' => 'boolean',
        'visible_in_ui' => 'boolean', 
        'verified_at' => 'datetime',
        'locations_count' => 'integer',
        'wage_reports_count' => 'integer',
    ];
}
```

### Route Model Binding

The Organization model supports flexible route binding that accepts both numeric IDs and string slugs.

**Custom Resolution Logic:**
```php
public function resolveRouteBinding($value, $field = null): ?Model
```

**Behavior:**
1. If `$field` is specified, use that field exactly
2. If value is numeric, try ID lookup first
3. If ID lookup fails or value is non-numeric, try slug lookup
4. Return `null` if no matches found

**Route Examples:**

```php
// routes/api.php
Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);

// These all work:
GET /api/v1/organizations/123        // Resolves by ID
GET /api/v1/organizations/starbucks  // Resolves by slug
GET /api/v1/organizations/mcdonalds-corp // Resolves by slug

// With explicit field binding:
Route::get('/organizations/{organization:slug}', [OrganizationController::class, 'show']);
GET /api/v1/organizations/starbucks  // Only resolves by slug
```

**Controller Usage:**
```php
public function show(Organization $organization)
{
    // $organization is automatically resolved
    return response()->json($organization);
}
```

### Observer Behavior

The `OrganizationObserver` handles domain normalization and cache invalidation.

#### Domain Normalization (On Saving)

**Process:**
1. Extracts domain from `website_url` field
2. Normalizes to lowercase hostname 
3. Removes `www.` prefix
4. Handles various URL formats
5. Sets normalized domain in `domain` field

**URL Format Support:**
```php
// Input examples -> Normalized domain output
'https://www.starbucks.com' -> 'starbucks.com'
'http://mcdonalds.com/us' -> 'mcdonalds.com' 
'walmart.com' -> 'walmart.com' (protocol added automatically)
'www.target.com' -> 'target.com'
'AMAZON.COM' -> 'amazon.com' (case normalized)
'invalid-url' -> '' (empty string on parsing failure)
```

**Usage Example:**
```php
$org = Organization::create([
    'name' => 'Starbucks',
    'website_url' => 'https://www.starbucks.com/store-locator'
]);

// Observer automatically sets:
// $org->domain = 'starbucks.com'
```

#### Cache Invalidation (On Saved/Deleted)

**Process:**
1. Increments cache version key `orgs:ver` on any save/delete
2. Triggers cache invalidation across the application
3. Ensures fresh data on next cache request

**Cache Key Management:**
```php
// Initial cache version set in AppServiceProvider
Cache::add('orgs:ver', 1, 0); // Never expires, default value 1

// Observer increments on changes
Cache::increment('orgs:ver'); // 1 -> 2 -> 3 -> ...
```

### Usage Examples

#### Basic CRUD Operations
```php
// Create organization
$org = Organization::create([
    'name' => 'Starbucks',
    'website_url' => 'https://www.starbucks.com',
    'primary_industry_id' => 5,
    'created_by' => auth()->id(),
]);
// Observer sets: domain = 'starbucks.com'

// Find by ID or slug
$org = Organization::find(1);
$org = Organization::bySlug('starbucks')->first();

// Update with observer
$org->update(['website_url' => 'https://starbucks.com/new-site']);
// Observer updates domain automatically and increments cache version
```

#### Complex Query Examples
```php
// Find all verified coffee shops in a specific area (when Location model exists)
Organization::defaultFilters()
    ->verified()
    ->inIndustry('food-service') 
    ->whereHas('locations', function($q) {
        $q->near(40.7128, -74.0060, 5); // Near NYC, 5km radius  
    })
    ->with(['primaryIndustry', 'locations'])
    ->paginate(20);

// Search for retail organizations with locations
Organization::search('target')
    ->inIndustry('retail')
    ->hasLocations()
    ->with('primaryIndustry')
    ->get();

// Get recently created organizations by industry
Organization::active()
    ->visible() 
    ->inIndustry('technology')
    ->with(['createdBy', 'primaryIndustry'])
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();

// Advanced search with multiple criteria
Organization::defaultFilters()
    ->where(function($query) {
        $query->search('coffee')
            ->orWhere(function($q) {
                $q->inIndustry('food-service')
                  ->where('verification_status', 'verified');
            });
    })
    ->hasLocations()
    ->orderBy('wage_reports_count', 'desc')
    ->paginate(15);
```

#### Route Binding Examples
```php
// Controller methods work with both ID and slug
class OrganizationController extends Controller  
{
    public function show(Organization $organization)
    {
        return response()->json([
            'data' => $organization->load(['primaryIndustry', 'locations']),
            'locations_count' => $organization->locations_count,
            'display_name' => $organization->display_name,
        ]);
    }
    
    public function locations(Organization $organization)  
    {
        return $organization->locations()
            ->with(['wageReports' => function($q) {
                $q->approved()->latest()->limit(5);
            }])
            ->paginate(20);
    }
}
```

#### Performance Optimization Examples
```php
// Efficient counting
$stats = [
    'total_organizations' => Organization::count(),
    'active_organizations' => Organization::active()->count(),
    'verified_organizations' => Organization::verified()->count(), 
    'organizations_with_locations' => Organization::hasLocations()->count(),
];

// Bulk loading with relationships
$organizations = Organization::defaultFilters()
    ->with([
        'primaryIndustry:id,name,slug',
        'createdBy:id,name',
        'verifiedBy:id,name'
    ])
    ->select(['id', 'name', 'slug', 'domain', 'primary_industry_id', 
              'created_by', 'verified_by', 'verification_status'])
    ->paginate(50);

// Efficient search with minimal data
$searchResults = Organization::search('starbucks')
    ->active()
    ->select(['id', 'name', 'slug', 'domain', 'locations_count'])
    ->limit(20)
    ->get();
```