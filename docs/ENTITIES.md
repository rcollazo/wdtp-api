# Entity Relationships Documentation

This document outlines the domain entities in the WDTP system and their relationships.

## Core Entities

### Organization Entity

**Purpose**: Represents a business entity (company, franchise, etc.) that operates one or more physical locations where wage data is collected.

#### Entity Properties

```php
class Organization extends Model
{
    // Core identification
    public string $name;              // Display name (max 160 chars)
    public string $slug;              // URL-safe identifier (unique)
    public ?string $legal_name;       // Official registered name
    public ?string $website_url;      // Primary website
    public ?string $domain;           // Email/website domain (case-insensitive unique)
    public ?string $description;      // Organization description
    public ?string $logo_url;         // Logo image URL
    
    // Industry classification
    public ?int $primary_industry_id; // FK to industries table
    
    // Status management
    public OrganizationStatus $status;           // active|inactive|suspended
    public VerificationStatus $verification_status; // unverified|pending|verified|rejected
    
    // Attribution
    public ?int $created_by;          // User who created record
    public ?int $verified_by;         // User who verified organization
    public ?Carbon $verified_at;      // When verification occurred
    
    // Performance counters
    public int $locations_count;      // Cached count of locations
    public int $wage_reports_count;   // Cached count of wage reports
    
    // Display flags
    public bool $is_active;           // Whether organization is active
    public bool $visible_in_ui;       // Whether to show in UI listings
    
    // Timestamps
    public Carbon $created_at;
    public Carbon $updated_at;
}
```

#### Business Rules

1. **Domain Uniqueness**: Domains are unique in a case-insensitive manner
   - "McDonalds.com" and "mcdonalds.com" cannot both exist
   - Implemented via PostgreSQL partial unique index on `lower(domain)`

2. **Slug Format**: Must follow URL-safe pattern
   - Pattern: `^[a-z0-9][a-z0-9_-]*[a-z0-9]$` or single character `^[a-z0-9]$`
   - Examples: "mcdonalds", "burger-king", "taco_bell", "7eleven"

3. **Verification Workflow**:
   ```
   unverified → pending → verified|rejected
   ```
   - Users create unverified organizations
   - Moderators/admins can promote to pending → verified
   - Verification affects organization trustworthiness in UI

4. **Status Management**:
   - `active`: Normal operation, accepts wage reports
   - `inactive`: Organization exists but not operational
   - `suspended`: Temporarily disabled by moderation

#### Relationships

```php
class Organization extends Model
{
    // Belongs to industry (optional)
    public function primaryIndustry(): BelongsTo
    {
        return $this->belongsTo(Industry::class, 'primary_industry_id');
    }
    
    // Has many locations (cascade delete)
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
    
    // Has many wage reports through locations
    public function wageReports(): HasManyThrough
    {
        return $this->hasManyThrough(WageReport::class, Location::class);
    }
    
    // Attribution relationships (nullable on delete)
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
```

#### Counter Maintenance Strategy

Performance counters are maintained through:

1. **Eloquent Model Events**:
   ```php
   // When location is created/deleted
   Location::created(fn($location) => $location->organization->increment('locations_count'));
   Location::deleted(fn($location) => $location->organization->decrement('locations_count'));
   ```

2. **Background Jobs** (for wage reports count):
   ```php
   // Recalculate wage reports count periodically
   $organization->wage_reports_count = $organization->wageReports()->approved()->count();
   ```

3. **Database Constraints**:
   - Counters are unsigned integers with default 0
   - Protected from negative values at database level

## Relationship Diagram

```
                    ┌─────────────┐
                    │  Industry   │
                    │             │
                    │ - id        │
                    │ - name      │
                    │ - parent_id │
                    └──────┬──────┘
                           │ 1:M (optional)
                           │ primary_industry_id
            ┌──────────────▼──────────────┐
            │      Organization          │
            │                           │
            │ - id                      │
            │ - name (indexed)          │
            │ - slug (unique)           │
            │ - domain (unique lower)   │
            │ - status                  │
            │ - verification_status     │
            │ - locations_count         │
            │ - wage_reports_count      │
            └──────────┬──────────────────┘
                       │ 1:M (cascade)
                       │ organization_id
            ┌──────────▼──────────────┐
            │      Location          │
            │                       │
            │ - id                  │
            │ - organization_id     │
            │ - point (PostGIS)     │
            │ - address             │
            │ - wage_reports_count  │
            └──────────┬──────────────┘
                       │ 1:M (cascade)
                       │ location_id
            ┌──────────▼──────────────┐
            │     WageReport         │
            │                       │
            │ - id                  │
            │ - location_id         │
            │ - wage_amount         │
            │ - status              │
            │ - helpful_votes       │
            └────────────────────────┘

     ┌─────────────┐                    ┌─────────────┐
     │    User     │◄─── created_by ────┤Organization │
     │             │                    │             │
     │ - id        │◄─── verified_by ───┤             │
     │ - name      │                    │             │
     │ - role      │                    │             │
     └─────────────┘                    └─────────────┘
```

## Query Patterns

### Common Organization Queries

1. **Find by Domain (Case-Insensitive)**:
   ```php
   Organization::whereRaw('lower(domain) = ?', [strtolower($domain)])->first();
   ```

2. **Active Organizations with Locations**:
   ```php
   Organization::where('status', 'active')
       ->where('is_active', true)
       ->has('locations')
       ->with(['primaryIndustry', 'locations'])
       ->paginate(20);
   ```

3. **Organizations Needing Verification**:
   ```php
   Organization::where('verification_status', 'pending')
       ->with(['createdBy', 'primaryIndustry'])
       ->orderBy('created_at')
       ->get();
   ```

4. **Organizations by Industry with Stats**:
   ```php
   Organization::where('primary_industry_id', $industryId)
       ->where('is_active', true)
       ->withCount(['locations', 'wageReports'])
       ->orderByDesc('wage_reports_count')
       ->get();
   ```

### Performance Considerations

1. **Domain Lookups**: Use raw SQL with `lower()` for case-insensitive searches
2. **Counter Fields**: Avoid expensive `COUNT()` queries in favor of cached counters
3. **Status Filtering**: Leverage composite indexes on `(status, is_active)`
4. **Eager Loading**: Always load related entities to prevent N+1 queries

## Data Integrity

### Cascade Behaviors

- **Industry Deletion**: `SET NULL` on `primary_industry_id`
- **Organization Deletion**: `CASCADE` delete all locations and wage reports
- **User Deletion**: `SET NULL` on attribution fields (preserve organization data)

### Validation Rules

1. **Slug Format**: Enforced at database level via CHECK constraint
2. **Domain Uniqueness**: Enforced via partial unique index on `lower(domain)`
3. **Status Transitions**: Enforced at application level
4. **Counter Consistency**: Maintained via background jobs and model events

## Future Relationships

As the system expands, additional relationships may include:

- **Organization → Certifications** (verification documents)
- **Organization → Reviews** (user-generated organization reviews)
- **Organization → CompanySize** (employee count categories)
- **Organization → ContactInfo** (support contact details)

These relationships should follow the same patterns established with existing entities.