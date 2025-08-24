# WDTP API Documentation

Complete API documentation for the What Do They Pay (WDTP) platform REST endpoints.

## Table of Contents

- [Base Configuration](#base-configuration)
- [Authentication](#authentication)
- [Common Patterns](#common-patterns)
- [Industries API](#industries-api)
- [Organizations API](#organizations-api)

---

## Base Configuration

**Base URL**: `/api/v1`
**Content Type**: `application/json`
**Authentication**: Laravel Sanctum (Bearer tokens)

### Standard Response Format

All API responses follow a consistent format:

**Success Response:**
```json
{
  "data": { /* resource data */ }
}
```

**Collection Response:**
```json
{
  "data": [ /* array of resources */ ],
  "links": {
    "first": "http://api.wdtp.local/api/v1/organizations?page=1",
    "last": "http://api.wdtp.local/api/v1/organizations?page=42",
    "prev": null,
    "next": "http://api.wdtp.local/api/v1/organizations?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 42,
    "path": "http://api.wdtp.local/api/v1/organizations",
    "per_page": 25,
    "to": 25,
    "total": 1042
  }
}
```

**Error Response:**
```json
{
  "message": "Validation failed",
  "errors": {
    "field_name": ["Error message"]
  }
}
```

### Common Query Parameters

**Pagination:**
- `per_page` (integer): Items per page (1-100, default: 25)
- `page` (integer): Page number (default: 1)

**Boolean Parameters:**
- Accept: `0`, `1`, `false`, `true` (case-insensitive)
- Convert to proper boolean values in controllers

---

## Authentication

### Required Headers
```http
Authorization: Bearer {token}
Content-Type: application/json
Accept: application/json
```

### Authentication Endpoints
- `POST /api/v1/auth/register` - User registration
- `POST /api/v1/auth/login` - User login (returns token)
- `POST /api/v1/auth/logout` - Token invalidation
- `GET /api/v1/auth/me` - Current user profile

---

## Common Patterns

### Search Parameters
- `q` (string, min: 2): Search term for text fields
- Case-insensitive ILIKE matching on relevant fields
- Results ordered by relevance when searching

### Filtering
- Boolean filters only apply when explicitly provided
- Missing parameters use model defaults
- Multiple filters can be combined

### Sorting
- `sort` parameter with predefined options
- Default sort varies by endpoint
- Search results default to `relevance` ordering

### Caching
- All read endpoints cached with version-based invalidation
- Standard Cache TTL: 300 seconds (5 minutes)
- Autocomplete Cache TTL: 600 seconds (10 minutes) for UI consistency
- Cache automatically invalidated when data changes

### Performance Optimizations

**Autocomplete Endpoint:**
- Minimal response format (id, name, slug only) reduces payload size
- Extended cache TTL (600s) provides consistent results during typing sessions
- Query field selection (`select(['id', 'name', 'slug'])`) minimizes database load
- Result limiting (max 50) prevents excessive memory usage
- Sub-100ms response times for optimal UI interaction

---

## Industries API

Complete documentation for industry endpoints with hierarchical structure support.

### List Industries

**Endpoint:** `GET /api/v1/industries`

**Description:** Retrieve industries list with optional hierarchical tree structure and pagination.

**Query Parameters:**
- `tree` (boolean): Return hierarchical tree structure instead of flat list
- `per_page` (integer, 1-100, default: 25): Items per page (ignored when tree=true)

**Cache Key:** `industries:{version}:index:{hash}`  
**Cache TTL:** 300 seconds

**Example Request:**
```http
GET /api/v1/industries?tree=true
```

**Example Response (Tree Structure):**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Food Service",
      "slug": "food-service",
      "description": "Restaurants, cafes, and food preparation services",
      "icon": "utensils",
      "is_active": true,
      "sort_order": 10,
      "children": [
        {
          "id": 5,
          "name": "Coffee Shop",
          "slug": "coffee-shop",
          "description": "Coffee shops and cafes",
          "icon": "coffee",
          "is_active": true,
          "sort_order": 10,
          "children": []
        }
      ]
    }
  ]
}
```

### Get Single Industry

**Endpoint:** `GET /api/v1/industries/{idOrSlug}`

**Description:** Retrieve a specific industry by ID or slug with full details including child industries.

**Parameters:**
- `idOrSlug` (required): Industry ID (integer) or slug (string)

**Cache Key:** `industries:{version}:show:{idOrSlug}`  
**Cache TTL:** 300 seconds

**Example Response:**
```json
{
  "data": {
    "id": 1,
    "name": "Food Service",
    "slug": "food-service",
    "description": "Restaurants, cafes, and food preparation services",
    "icon": "utensils",
    "is_active": true,
    "sort_order": 10,
    "parent": null,
    "children": [
      {
        "id": 5,
        "name": "Coffee Shop",
        "slug": "coffee-shop"
      }
    ]
  }
}
```

### Industry Autocomplete

**Endpoint:** `GET /api/v1/industries/autocomplete`

**Description:** Search industries for autocomplete/typeahead functionality.

**Query Parameters:**
- `q` (required, string, min: 2): Search term

**Cache Key:** `industries:{version}:autocomplete:{hash}`  
**Cache TTL:** 300 seconds

**Example Response:**
```json
{
  "data": [
    {
      "id": 5,
      "name": "Coffee Shop",
      "slug": "coffee-shop"
    }
  ]
}
```

---

## Organizations API

Complete documentation for organization endpoints with comprehensive search, filtering, and caching.

### List Organizations

**Endpoint:** `GET /api/v1/organizations`

**Description:** Get paginated list of organizations with advanced search, filtering, and sorting capabilities.

**Query Parameters:**
- `q` (string, min: 2): Search term for organization name, legal name, or domain (case-insensitive)
- `industry_id` (integer): Filter by primary industry ID
- `industry_slug` (string): Filter by primary industry slug  
- `verified` (boolean): Filter by verification status
- `has_locations` (boolean): Filter organizations that have locations
- `per_page` (integer, 1-100, default: 25): Items per page
- `sort` (string, default: "name"): Sort order

**Sort Options:**
- `relevance`: Search relevance (only effective when `q` parameter provided)
- `name`: Alphabetical by organization name
- `locations`: By locations count (descending)
- `wage_reports`: By wage reports count (descending)  
- `updated`: By last update time (descending)

**Default Filters Applied:**
- `is_active = true`
- `visible_in_ui = true`
- `status = 'approved'`

**Cache Key:** `orgs:{version}:index:{hash}`  
**Cache TTL:** 300 seconds

**Example Request:**
```http
GET /api/v1/organizations?q=starbucks&verified=true&sort=locations&per_page=10
```

**Example Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Starbucks",
      "slug": "starbucks", 
      "domain": "starbucks.com",
      "primary_industry": {
        "id": 5,
        "name": "Coffee Shop",
        "slug": "coffee-shop"
      },
      "locations_count": 8450,
      "wage_reports_count": 2341,
      "is_verified": true
    }
  ],
  "links": {
    "first": "http://api.wdtp.local/api/v1/organizations?page=1",
    "last": "http://api.wdtp.local/api/v1/organizations?page=5", 
    "prev": null,
    "next": "http://api.wdtp.local/api/v1/organizations?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "path": "http://api.wdtp.local/api/v1/organizations",
    "per_page": 10,
    "to": 10,
    "total": 47
  }
}
```

**Search Behavior:**
- Searches across `name`, `legal_name`, and `domain` fields
- Uses PostgreSQL ILIKE for case-insensitive matching
- Results automatically ordered by relevance when searching
- Minimum 2 characters required for search term

**Filtering Logic:**
- `industry_id` and `industry_slug` are mutually exclusive (slug takes precedence)
- `verified` and `has_locations` filters only apply when explicitly provided
- Boolean parameters accept: `0`, `1`, `false`, `true`

**Performance Notes:**
- All responses cached for 5 minutes with automatic invalidation
- Eager loads `primaryIndustry` relationship to prevent N+1 queries
- Uses database indexes on commonly filtered fields

### Get Single Organization

**Endpoint:** `GET /api/v1/organizations/{idOrSlug}`

**Description:** Retrieve a specific organization by ID or slug with complete details.

**Parameters:**
- `idOrSlug` (required): Organization ID (integer) or slug (string)

**Resolution Logic:**
- If parameter is numeric, searches by ID
- If parameter is non-numeric, searches by slug
- Uses `defaultFilters()` scope (active, visible, approved)

**Cache Key:** `orgs:{version}:show:{idOrSlug}`  
**Cache TTL:** 300 seconds

**Example Request:**
```http
GET /api/v1/organizations/starbucks
# OR
GET /api/v1/organizations/1
```

**Example Response:**
```json
{
  "data": {
    "id": 1,
    "name": "Starbucks",
    "slug": "starbucks",
    "domain": "starbucks.com",
    "primary_industry": {
      "id": 5,
      "name": "Coffee Shop", 
      "slug": "coffee-shop"
    },
    "locations_count": 8450,
    "wage_reports_count": 2341,
    "is_verified": true,
    "legal_name": "Starbucks Corporation",
    "website_url": "https://starbucks.com",
    "description": "Global coffeehouse chain founded in 1971, serving specialty coffee and beverages.",
    "logo_url": "https://cdn.wdtp.app/logos/starbucks.png",
    "verified_at": "2024-01-15T10:30:00.000Z",
    "created_at": "2023-12-01T08:15:30.000Z",
    "updated_at": "2024-01-20T14:22:45.000Z"
  }
}
```

**Response Fields:**

| Field | Type | Description | Example |
|-------|------|-------------|---------|
| `id` | integer | Primary key | `1` |
| `name` | string | Display name | `"Starbucks"` |
| `slug` | string | URL-friendly identifier | `"starbucks"` |
| `domain` | string | Primary domain | `"starbucks.com"` |
| `legal_name` | string\|null | Legal business name | `"Starbucks Corporation"` |
| `website_url` | string\|null | Primary website URL | `"https://starbucks.com"` |
| `description` | string\|null | Organization description | `"Global coffeehouse chain..."` |
| `logo_url` | string\|null | Logo image URL | `"https://cdn.wdtp.app/logos/starbucks.png"` |
| `primary_industry` | object\|null | Industry relationship | `{"id":5,"name":"Coffee Shop","slug":"coffee-shop"}` |
| `locations_count` | integer | Number of locations | `8450` |
| `wage_reports_count` | integer | Number of wage reports | `2341` |
| `is_verified` | boolean | Computed from verification_status | `true` |
| `verified_at` | string\|null | ISO 8601 verification timestamp | `"2024-01-15T10:30:00.000Z"` |
| `created_at` | string | ISO 8601 creation timestamp | `"2023-12-01T08:15:30.000Z"` |
| `updated_at` | string | ISO 8601 update timestamp | `"2024-01-20T14:22:45.000Z"` |

**Hidden Fields:**
The following internal fields are excluded from API responses:
- `verification_status` (raw enum value, use `is_verified` boolean)
- `review_notes` (internal moderation notes)
- `created_by`, `verified_by` (internal user references)

### Organization Autocomplete

**Endpoint:** `GET /api/v1/organizations/autocomplete`

**Description:** Fast autocomplete endpoint for organization search with minimal response format optimized for typeahead interfaces.

**Query Parameters:**
- `q` (required, string, min: 2): Search term for organization name
- `limit` (optional, integer, 1-50, default: 10): Maximum number of results

**Cache Key:** `orgs:{version}:ac:{hash}`  
**Cache TTL:** 600 seconds (extended for autocomplete data stability)

**Example Request:**
```http
GET /api/v1/organizations/autocomplete?q=starb&limit=5
```

**Example Response (Minimal Format):**
```json
[
  {
    "id": 1,
    "name": "Starbucks",
    "slug": "starbucks"
  },
  {
    "id": 15,
    "name": "Starbucks Reserve",
    "slug": "starbucks-reserve"
  }
]
```

**Performance Characteristics:**
- **Minimal Response Format**: Only returns `id`, `name`, and `slug` fields for optimal payload size
- **Extended Cache TTL**: 600 seconds (vs 300s for other endpoints) due to autocomplete data stability
- **Query Optimization**: Uses `select()` to limit database fields and reduce memory usage
- **Fast Response Times**: Optimized for UI interaction with sub-100ms response goals

**Use Cases:**
- UI typeahead/autocomplete interfaces
- Quick organization selection in forms
- Search suggestions for user input
- Progressive enhancement from autocomplete to full organization details

**Integration Pattern:**
```javascript
// Typical frontend usage
const suggestions = await fetch(`/api/v1/organizations/autocomplete?q=${term}`)
  .then(res => res.json());

// Then fetch full details when user selects
const fullOrganization = await fetch(`/api/v1/organizations/${selectedSlug}`)
  .then(res => res.json());
```

**Error Response:**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "q": ["The q field is required.", "The q field must be at least 2 characters."],
    "limit": ["The limit field must be between 1 and 50."]
  }
}
```

**Validation Rules:**
- `q`: `required|string|min:2`
- `limit`: `integer|min:1|max:50`

### Error Responses

**400 Bad Request:**
```json
{
  "message": "Bad Request"
}
```

**404 Not Found:**
```json
{
  "message": "No query results for model [App\\Models\\Organization] 1"
}
```

**422 Validation Error:**
```json
{
  "message": "The q field must be at least 2 characters.",
  "errors": {
    "q": ["The q field must be at least 2 characters."],
    "per_page": ["The per page field must be between 1 and 100."],
    "industry_id": ["The selected industry id is invalid."]
  }
}
```

### Parameter Validation Rules

**Index Endpoint:**
- `q`: `sometimes|string|min:2`
- `industry_id`: `sometimes|integer|exists:industries,id`
- `industry_slug`: `sometimes|string|exists:industries,slug`
- `verified`: `sometimes|in:0,1,false,true`
- `has_locations`: `sometimes|in:0,1,false,true`
- `per_page`: `sometimes|integer|min:1|max:100`
- `sort`: `sometimes|in:relevance,name,locations,wage_reports,updated`

**Show Endpoint:**
- `idOrSlug`: Resolved automatically based on numeric/non-numeric format
- Must exist in database with `defaultFilters()` applied

### Caching Strategy

**Cache Version Management:**
- Base version key: `orgs:ver`
- Increments automatically when any organization changes
- All organization caches invalidated when version increments

**Cache Key Patterns:**
```php
// Index requests
"orgs:{version}:index:{hash}" 
// Hash includes all query parameters

// Show requests  
"orgs:{version}:show:{idOrSlug}"
// Separate cache entry per ID or slug
```

**Cache Invalidation Triggers:**
- Organization created, updated, or deleted
- Verification status changes
- Industry assignment changes
- Location count changes (via observer)
- Organization status changes

**Performance Optimizations:**
- Eager loads `primaryIndustry` relationship
- Uses `withCount()` for aggregate fields
- Database indexes on filtered columns
- Paginated responses for large result sets

---

## Complete API Integration Patterns

This section demonstrates how to integrate all Organization endpoints in a typical frontend application workflow.

### Progressive Enhancement Workflow

The Organizations API is designed for progressive enhancement, allowing efficient user interactions from quick search to detailed views:

```javascript
// 1. Start with autocomplete for instant feedback
async function searchOrganizations(term) {
    if (term.length < 2) return [];
    
    const response = await fetch(`/api/v1/organizations/autocomplete?q=${term}&limit=5`);
    return response.json();
}

// 2. Show filtered list with pagination for browsing
async function browseOrganizations(filters = {}) {
    const params = new URLSearchParams({
        per_page: 25,
        sort: 'locations',
        ...filters
    });
    
    const response = await fetch(`/api/v1/organizations?${params}`);
    return response.json();
}

// 3. Get full details when user selects organization
async function getOrganizationDetails(idOrSlug) {
    const response = await fetch(`/api/v1/organizations/${idOrSlug}`);
    return response.json();
}
```

### Typical Integration Scenarios

**Scenario 1: Search-First Interface**
```javascript
// User types "starb" in search box
const suggestions = await searchOrganizations('starb');
// Returns: [{"id": 1, "name": "Starbucks", "slug": "starbucks"}]

// User clicks suggestion, get full details
const organization = await getOrganizationDetails('starbucks');
// Returns complete organization data with verification status, industry info, etc.
```

**Scenario 2: Industry-Based Browsing**
```javascript
// User browses coffee shops in a specific industry
const coffeeShops = await browseOrganizations({
    industry_slug: 'coffee-shop',
    verified: true,
    sort: 'wage_reports'
});

// Returns paginated list with location counts and wage report counts
// User can then drill down to specific organizations
```

**Scenario 3: Location-Aware Search**
```javascript
// Future: will include spatial queries
const nearbyOrganizations = await browseOrganizations({
    q: 'restaurant',
    has_locations: true,
    near: '40.7128,-74.0060',  // Future implementation
    radius_km: 5               // Future implementation
});
```

### Performance Integration Strategy

**Caching Optimization:**
- **Autocomplete**: 600-second cache provides stability during typing sessions
- **Index/Show**: 300-second cache balances freshness with performance  
- **Progressive Loading**: Start with autocomplete, upgrade to full data as needed

**Response Size Management:**
```javascript
// Minimal payload for autocomplete (3 fields only)
const autocompleteSize = '~50 bytes per result';

// Optimized list items for browsing (7-8 key fields)  
const listItemSize = '~200 bytes per result';

// Complete data for detail views (all fields)
const fullDetailSize = '~500 bytes per result';
```

**Client-Side Optimization:**
```javascript
// Cache autocomplete results to reduce API calls
const autocompleteCache = new Map();

async function cachedAutocomplete(term) {
    if (autocompleteCache.has(term)) {
        return autocompleteCache.get(term);
    }
    
    const results = await searchOrganizations(term);
    autocompleteCache.set(term, results);
    
    // Expire cache after 5 minutes to match server TTL
    setTimeout(() => autocompleteCache.delete(term), 300000);
    
    return results;
}
```

### Error Handling Integration

**Comprehensive Error Handling:**
```javascript
async function handleOrganizationRequest(apiCall) {
    try {
        const response = await apiCall();
        
        if (!response.ok) {
            const error = await response.json();
            throw new OrganizationApiError(error.message, response.status, error.errors);
        }
        
        return response.json();
    } catch (error) {
        if (error instanceof OrganizationApiError) {
            // Handle validation errors, show specific field errors
            displayValidationErrors(error.errors);
        } else if (error.status === 404) {
            // Organization not found, redirect to search
            redirectToSearch();
        } else {
            // Network or server error
            displayGenericError();
        }
        throw error;
    }
}

class OrganizationApiError extends Error {
    constructor(message, status, errors = null) {
        super(message);
        this.status = status;
        this.errors = errors;
    }
}
```

### Complete API Integration Summary

**Endpoint Relationships:**
1. **Autocomplete** → **Index**: User types, gets suggestions, browses filtered results
2. **Index** → **Show**: User browses list, clicks for full organization details  
3. **Show** → **Related Data**: Full view can link to locations, wage reports (future)

**Data Flow Optimization:**
- **Autocomplete**: Instant feedback with 600s cache for typing consistency
- **Index**: Comprehensive search/filter with 300s cache and pagination
- **Show**: Complete data with 300s cache and eager-loaded relationships

**Performance Characteristics:**
- **Autocomplete**: < 100ms response time for optimal UX
- **Index**: < 200ms for paginated results with filtering
- **Show**: < 150ms for single organization with relationships
- **Cache Hit Rate**: > 80% in typical usage patterns

---

## Rate Limiting

All API endpoints are subject to rate limiting:

**Default Limits:**
- Authenticated: 60 requests per minute
- Unauthenticated: 30 requests per minute

**Headers Included:**
- `X-RateLimit-Limit`: Request limit per window
- `X-RateLimit-Remaining`: Remaining requests
- `X-RateLimit-Reset`: Unix timestamp of next window

---

## Pagination

All collection endpoints support pagination with consistent metadata:

**Query Parameters:**
- `page`: Page number (default: 1)
- `per_page`: Items per page (1-100, default: 25)

**Response Meta:**
- `current_page`: Current page number
- `from`: First item number on current page
- `to`: Last item number on current page
- `per_page`: Items per page
- `last_page`: Total number of pages
- `total`: Total number of items
- `path`: Base URL for pagination

**Links:**
- `first`: First page URL
- `last`: Last page URL
- `prev`: Previous page URL (null if first page)
- `next`: Next page URL (null if last page)

---

## OpenAPI/Swagger Documentation

Interactive API documentation available at:
- **Development**: `http://localhost/api/documentation`
- **Staging**: `https://api-staging.wdtp.app/api/documentation`

Features:
- Complete endpoint documentation with examples
- Interactive request testing
- Schema definitions for all resources
- Parameter validation information
- Authentication testing interface

The Swagger UI provides a complete testing environment for all documented endpoints with real-time validation and response examples.