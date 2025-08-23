# API Resources Documentation

This document describes the API resource classes used to transform model data into JSON responses for the WDTP API.

## Overview

API Resources provide a transformation layer between Eloquent models and JSON API responses. They ensure consistent data formatting, control field visibility, and optimize performance through conditional field inclusion.

## Resource Inheritance Patterns

### Two-Tier Organization Resources

The Organization resources use an inheritance pattern optimized for different use cases:

- **OrganizationListItemResource**: Minimal format for list views and search results
- **OrganizationResource**: Extended format inheriting from ListItem with additional detail fields

This pattern reduces payload size for bulk operations while providing full detail when needed.

## Organization Resources

### OrganizationListItemResource

**Purpose**: Minimal organization data for list views, search results, and references.

**Fields:**

| Field | Type | Description | Example |
|-------|------|-------------|---------|
| `id` | integer | Primary key | `1` |
| `name` | string | Display name | `"Starbucks"` |
| `slug` | string | URL-friendly identifier | `"starbucks"` |
| `domain` | string | Primary domain | `"starbucks.com"` |
| `primary_industry` | object\|null | Inline industry object (conditional) | `{"id":5,"name":"Coffee Shop","slug":"coffee-shop"}` |
| `locations_count` | integer | Number of locations | `8450` |
| `wage_reports_count` | integer | Number of wage reports | `2341` |
| `is_verified` | boolean | Computed from verification_status | `true` |

**Example Response:**
```json
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
```

### OrganizationResource

**Purpose**: Complete organization data for detail views and full API responses.

**Inheritance**: Extends OrganizationListItemResource using `array_merge()` pattern.

**Additional Fields:**

| Field | Type | Description | Example |
|-------|------|-------------|---------|
| `legal_name` | string\|null | Legal business name | `"Starbucks Corporation"` |
| `website_url` | string\|null | Primary website URL | `"https://starbucks.com"` |
| `description` | string\|null | Organization description | `"Global coffeehouse chain..."` |
| `logo_url` | string\|null | Logo image URL | `"https://cdn.wdtp.app/logos/starbucks.png"` |
| `verified_at` | string\|null | ISO 8601 verification timestamp | `"2024-01-15T10:30:00.000Z"` |
| `created_at` | string | ISO 8601 creation timestamp | `"2023-12-01T08:15:30.000Z"` |
| `updated_at` | string | ISO 8601 update timestamp | `"2024-01-20T14:22:45.000Z"` |

**Example Response:**
```json
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
  "is_verified": true,
  "legal_name": "Starbucks Corporation",
  "website_url": "https://starbucks.com",
  "description": "Global coffeehouse chain founded in 1971, serving specialty coffee and beverages.",
  "logo_url": "https://cdn.wdtp.app/logos/starbucks.png",
  "verified_at": "2024-01-15T10:30:00.000Z",
  "created_at": "2023-12-01T08:15:30.000Z",
  "updated_at": "2024-01-20T14:22:45.000Z"
}
```

## Field Mapping Details

### Computed Fields

- **`is_verified`**: Computed from `verification_status` field (`=== 'verified'`)
- **`primary_industry`**: Conditional inline object from `primaryIndustry` relationship

### Conditional Field Inclusion

**Industry Relationship Loading:**
```php
// Only included when relationship is loaded to prevent N+1 queries
'primary_industry' => $this->when($this->relationLoaded('primaryIndustry'), 
    fn () => $this->primaryIndustry ? [
        'id' => $this->primaryIndustry->id,
        'name' => $this->primaryIndustry->name,
        'slug' => $this->primaryIndustry->slug,
    ] : null
)
```

### Date Formatting

All timestamps use ISO 8601 format via `toISOString()`:
- `verified_at`: Nullable, only present when verification_status is 'verified'
- `created_at`, `updated_at`: Always present in full resource

## Usage Patterns

### When to Use Each Resource

**OrganizationListItemResource:**
- Organization search endpoints (`/api/v1/organizations`)
- Reference data in other resources
- Paginated lists where payload size matters
- Autocomplete/suggestion endpoints

**OrganizationResource:**
- Single organization detail (`/api/v1/organizations/{id}`)
- Organization creation/update responses
- Admin/management interfaces requiring full data

### Performance Considerations

**Relationship Loading:**
```php
// Efficient for list views
Organization::query()
    ->with('primaryIndustry')
    ->withCount(['locations', 'wageReports'])
    ->get();

// Use sparse fieldsets for large collections
Organization::select(['id', 'name', 'slug', 'domain'])
    ->with('primaryIndustry:id,name,slug')
    ->get();
```

**N+1 Query Prevention:**
- Always load `primaryIndustry` relationship when using `primary_industry` field
- Use `withCount()` for aggregate counts
- Consider paginated responses for large result sets

## Industry Relationship Format

Organizations reference industries using a consistent inline object format:

```json
{
  "id": 5,
  "name": "Coffee Shop", 
  "slug": "coffee-shop"
}
```

This format provides enough information for display without requiring additional API calls, while maintaining referential integrity through the ID field.

## Collection Responses

When returning multiple organizations, use Laravel's Resource Collections:

```php
// Controller example
return OrganizationListItemResource::collection($organizations);

// Paginated collection
return OrganizationListItemResource::collection(
    $organizations->paginate(20)
);
```

## Error Handling

Resources handle missing relationships gracefully:
- Null relationships return `null` for conditional fields
- Missing counts default to 0
- Dates use null-safe operators (`?->`)

## Extension Guidelines

When adding new fields to Organization resources:

1. **Add to ListItem** if field is needed for list views
2. **Add to full Resource** for detail-only fields
3. **Use conditional inclusion** for expensive computed fields
4. **Maintain consistent naming** with existing patterns
5. **Document performance implications** of new relationships