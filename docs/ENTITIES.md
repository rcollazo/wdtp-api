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
    public int $wage_reports_count;   // Cached count of approved wage reports
    
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

#### Database Schema Updates
**Table**: `organizations`

**Counter Fields Added**:
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| wage_reports_count | INTEGER | DEFAULT 0, NOT NULL | Count of approved wage reports for this organization |

**Counter Maintenance**:
- Automatically maintained by WageReportObserver
- Only counts approved wage reports
- Updated atomically with underflow protection
- Initialized from existing data during migration
- Indexed for performance queries

#### Counter Maintenance Strategy

Performance counters are maintained through:

1. **WageReportObserver Events**:
   ```php
   // Atomic counter updates with transaction safety
   DB::transaction(function () use ($wageReport) {
       if ($wageReport->status === 'approved') {
           Organization::where('id', $wageReport->organization_id)
               ->increment('wage_reports_count');
       }
   });
   ```

2. **Underflow Protection**:
   ```php
   // Safe decrement with underflow prevention
   Organization::where('id', $organizationId)
       ->where('wage_reports_count', '>', 0)
       ->decrement('wage_reports_count');
   ```

3. **Database Constraints**:
   - Counters are unsigned integers with default 0
   - Protected from negative values at database level
   - Only approved wage reports increment counters

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
            │ - latitude            │
            │ - longitude           │
            │ - address             │
            │ - wage_reports_count  │
            └──────────┬──────────────┘
                       │ 1:M (cascade)
                       │ location_id
            ┌──────────▼──────────────┐
            │     WageReport         │
            │                       │
            │ - id                  │
            │ - user_id (nullable)  │
            │ - organization_id     │
            │ - location_id         │
            │ - job_title           │
            │ - employment_type     │
            │ - wage_period         │
            │ - amount_cents        │
            │ - normalized_hourly_cents │
            │ - status              │
            │ - sanity_score        │
            │ - effective_date      │
            │ - tips_included       │
            │ - unionized           │
            └──────────┬──────────────┘
                       │ M:1 (nullable)
                       │ user_id
     ┌─────────────────▼─────┐        ┌─────────────┐
     │        User           │◄───────┤Organization │
     │                       │        │             │
     │ - id                  │◄───────┤             │
     │ - name                │        │             │
     │ - email               │        │             │
     │ - role                │        │             │
     │ - experience_points   │        │             │
     │ - level               │        │             │
     └───────────────────────┘        └─────────────┘
           ▲                                ▲
           │ created_by/verified_by         │
           └────────────────────────────────┘
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

### Location Entity

**Purpose**: Represents a physical business location where wage data is collected. Each location belongs to an organization and has spatial coordinates for geographic searches.

#### Database Schema Updates
**Table**: `locations`

**Counter Fields Added**:
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| wage_reports_count | INTEGER | DEFAULT 0, NOT NULL | Count of approved wage reports for this location |

**Counter Maintenance**:
- Automatically maintained by WageReportObserver
- Only counts approved wage reports
- Updated atomically with underflow protection
- Initialized from existing data during migration
- Indexed for performance queries

#### Core Location Properties

```php
class Location extends Model
{
    // Organization relationship
    public ?int $organization_id;     // FK to organizations table
    
    // Identity and naming
    public ?string $name;             // Location name (e.g., "Times Square Store")
    public ?string $slug;             // URL-safe identifier
    
    // Address components
    public string $address_line_1;    // Primary address (required)
    public ?string $address_line_2;   // Secondary address (apt, suite, etc.)
    public string $city;              // City name (required)
    public string $state_province;    // State/province (required)
    public ?string $postal_code;      // ZIP/postal code
    public string $country_code;      // ISO country code (default 'US')
    
    // Contact information
    public ?string $phone;            // Phone number
    public ?string $website_url;      // Location-specific website
    public ?string $description;      // Location description
    
    // Spatial coordinates (dual storage architecture)
    public decimal $latitude;         // Cached for quick access (-90 to 90)
    public decimal $longitude;        // Cached for sorting (-180 to 180)
    // PostGIS point column for spatial queries (managed automatically)
    
    // Performance counter
    public int $wage_reports_count;   // Count of approved wage reports
    
    // Status flags
    public bool $is_active;           // Whether location is active (default true)
    public bool $is_verified;         // Whether location is verified (default false)
    public ?string $verification_notes; // Admin notes for verification process
    
    // Timestamps
    public Carbon $created_at;
    public Carbon $updated_at;
}
```

#### Spatial Architecture

Locations use a dual storage approach for optimal performance:

1. **Cached Coordinates**: `latitude` and `longitude` decimal fields for quick access and sorting
2. **PostGIS Point**: `point` geography column for precise spatial queries and distance calculations

**PostGIS Integration**:
```sql
-- Automatically maintained PostGIS point column
ALTER TABLE locations ADD COLUMN point GEOGRAPHY(POINT, 4326);

-- GiST spatial index for <200ms query performance
CREATE INDEX locations_point_gist_idx ON locations USING GIST (point);

-- Full-text search index for location search
CREATE INDEX locations_name_address_city_fulltext 
    ON locations USING gin(to_tsvector('english', 
    coalesce(name, '') || ' ' || 
    coalesce(address_line_1, '') || ' ' || 
    coalesce(city, '')));
```

#### Spatial Query Scopes

```php
class Location extends Model
{
    // Find locations within radius of coordinates
    public function scopeNear(Builder $query, float $lat, float $lon, int $radiusKm = 10): Builder
    {
        $radiusMeters = $radiusKm * 1000;
        return $query->whereRaw(
            'ST_DWithin(point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
            [$lon, $lat, $radiusMeters]
        );
    }
    
    // Add distance calculation to results
    public function scopeWithDistance(Builder $query, float $lat, float $lon): Builder
    {
        return $query->selectRaw(
            'locations.*, ST_Distance(point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_meters',
            [$lon, $lat]
        );
    }
    
    // Order by distance from coordinates
    public function scopeOrderByDistance(Builder $query, float $lat, float $lon): Builder
    {
        return $query->orderByRaw(
            'ST_Distance(point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)',
            [$lon, $lat]
        );
    }
    
    // Search by name, address, or city (full-text search)
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = '%' . $term . '%';
        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'ILIKE', $term)
                ->orWhere('address_line_1', 'ILIKE', $term)
                ->orWhere('city', 'ILIKE', $term);
        });
    }
}
```

#### Location Relationships

```php
class Location extends Model
{
    // Belongs to organization (cascade delete from organization)
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
    
    // Has many wage reports (cascade delete when location deleted)
    public function wageReports(): HasMany
    {
        return $this->hasMany(WageReport::class);
    }
}
```

#### Performance Characteristics

**Spatial Query Performance**:
- All spatial queries complete within 200ms (tested requirement)
- GiST index utilization verified for PostGIS operations
- Distance calculations accurate to ±25m (tested tolerance)
- Supports up to 10,000 locations with sub-second response times

**Counter Management**:
- Real-time updates via WageReportObserver
- Atomic increments/decrements with underflow protection
- Only approved wage reports count toward totals
- Efficient queries using denormalized counters instead of COUNT(*) operations

## WageReport Entity

### Overview
The WageReport entity represents anonymous hourly wage submissions for specific business locations. It includes comprehensive normalization, sanity scoring, and gamification integration to ensure data quality while maintaining anonymity.

### Database Schema
**Table**: `wage_reports`

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | BIGINT | PRIMARY KEY | Auto-incrementing ID |
| user_id | BIGINT | NULLABLE, FK(users.id) ON DELETE SET NULL | Submitting user (anonymous allowed) |
| organization_id | BIGINT | NULLABLE, FK(organizations.id) ON DELETE SET NULL | Derived from location |
| location_id | BIGINT | NOT NULL, FK(locations.id) ON DELETE CASCADE | Required location reference |
| job_title | VARCHAR(160) | NOT NULL | Job position title |
| employment_type | ENUM | NOT NULL, DEFAULT 'full_time' | full_time, part_time, seasonal, contract |
| wage_period | ENUM | NOT NULL | hourly, weekly, biweekly, monthly, yearly, per_shift |
| currency | CHAR(3) | NOT NULL, DEFAULT 'USD' | ISO currency code |
| amount_cents | INT UNSIGNED | NOT NULL, CHECK > 0 | Original wage amount in cents |
| normalized_hourly_cents | INT UNSIGNED | NOT NULL, CHECK > 0 | Calculated hourly rate in cents |
| hours_per_week | SMALLINT UNSIGNED | NULLABLE | Work hours context |
| effective_date | DATE | NULLABLE | When wage was/is effective |
| tips_included | BOOLEAN | DEFAULT false | Whether tips are included |
| unionized | BOOLEAN | NULLABLE | Union membership status |
| source | ENUM | DEFAULT 'user' | user, public_posting, employer_claim, other |
| status | ENUM | DEFAULT 'approved' | approved, pending, rejected |
| sanity_score | SMALLINT | DEFAULT 0 | MAD-based outlier score (-5 to 5) |
| notes | TEXT | NULLABLE | Additional context |
| created_at | TIMESTAMP | NOT NULL | Creation timestamp |
| updated_at | TIMESTAMP | NOT NULL | Last update timestamp |

### Relationships

```php
class WageReport extends Model
{
    // Belongs to user (nullable for anonymous submissions)
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    // Belongs to organization (auto-derived from location)
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
    
    // Belongs to location (required)
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
```

### Indexes
- **Primary**: `(id)`
- **Foreign Keys**: `(user_id)`, `(organization_id)`, `(location_id)`
- **Query Optimization**: `(status)`, `(effective_date)`, `(normalized_hourly_cents)`
- **Composite**: `(location_id, status)`, `(organization_id, status)`
- **Search**: `(job_title)`, `(employment_type)`, `(wage_period)`, `(currency)`

### Normalization Engine
All wage periods are normalized to hourly rates using integer-only math to avoid floating-point precision issues:

| Period | Formula | Notes |
|--------|---------|-------|
| hourly | amount_cents | No conversion needed |
| weekly | amount_cents ÷ hours_per_week | Uses provided or default hours |
| biweekly | amount_cents ÷ (2 × hours_per_week) | Bi-weekly to hourly |
| monthly | (amount_cents × 12) ÷ (52 × hours_per_week) | Assumes 52 weeks/year |
| yearly | amount_cents ÷ (52 × hours_per_week) | Annual salary to hourly |
| per_shift | amount_cents ÷ shift_hours | Uses provided or default shift |

**Normalization Constants**:
- `DEFAULT_HOURS_PER_WEEK`: 40
- `DEFAULT_SHIFT_HOURS`: 8
- `MIN_HOURLY_CENTS`: 200 ($2.00/hour)
- `MAX_HOURLY_CENTS`: 20000 ($200.00/hour)

#### Normalization Examples

| Input | Period | Hours/Week | Normalized Hourly |
|-------|--------|------------|-------------------|
| $60,000 | yearly | 40 | $28.85/hour |
| $1,200 | weekly | 30 | $40.00/hour |
| $4,000 | monthly | 40 | $23.08/hour |
| $120 | per_shift | 8 hours | $15.00/hour |
| $25 | hourly | N/A | $25.00/hour |

All calculations preserve precision using integer cents and prevent float precision errors.

### Observer Pattern & Business Logic

#### WageReportObserver
Handles all lifecycle events with comprehensive business logic:

**Creating Event**:
- Derives organization_id from location relationship
- Calculates normalized_hourly_cents using normalization engine
- Computes sanity_score using MAD (Median Absolute Deviation) algorithm
- Sets status (approved/pending) based on sanity score threshold

**Created Event**:
- Increments wage_reports_count on location and organization (approved only)
- Awards experience points (10 XP + 25 XP first report bonus)
- Bumps cache version keys (wages:ver, orgs:ver, locations:ver)

**Updated Event**:
- Recalculates normalization if wage fields changed
- Updates counters if status changed (approved ↔ pending/rejected)
- Bumps cache version keys for invalidation

**Deleted/Restored Events**:
- Decrements/increments counters with underflow protection
- Bumps cache version keys consistently

#### Sanity Scoring Algorithm
Uses Median Absolute Deviation (MAD) for robust outlier detection:

**Statistical Hierarchy**:
1. **Location Level** (≥3 approved reports): Calculate median and MAD for location
2. **Organization Level** (fallback): Use organization-wide statistics  
3. **Global Bounds** (final fallback): MIN/MAX_HOURLY_CENTS validation

**MAD Calculation Process**:
```sql
-- Calculate location statistics
SELECT 
    COUNT(*) as count,
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY normalized_hourly_cents) as median,
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY ABS(normalized_hourly_cents - median)) as mad
FROM wage_reports 
WHERE location_id = ? AND status = 'approved'
```

**Scoring Scale**:
- **Score = 5**: Normal range (deviation < 1.5 × MAD)
- **Score = 0**: Slight concern (1.5 × MAD ≤ deviation < 3 × MAD)
- **Score = -2**: Moderate outlier (3 × MAD ≤ deviation < 6 × MAD)
- **Score = -5**: Strong outlier (deviation ≥ 6 × MAD)
- **K_MAD = 6**: Conservative threshold for outlier detection

**Status Assignment**:
- `sanity_score >= 0` → `approved` status
- `sanity_score < 0` → `pending` status (requires moderation)

#### Sanity Score Examples

**Example 1: Fast food restaurant in NYC**
- Median wage: $15.00/hour (1500 cents)
- MAD: $2.00/hour (200 cents)
- New report: $8.00/hour (800 cents)
- Deviation: |800 - 1500| = 700 cents
- Score: -(700 ÷ (6 × 200)) = -0.58 → **Pending status**

**Example 2: Tech company in SF**
- Median wage: $45.00/hour (4500 cents)
- MAD: $8.00/hour (800 cents)
- New report: $50.00/hour (5000 cents)
- Deviation: |5000 - 4500| = 500 cents
- Score: Normal range (500 < 1.5 × 800) → **Approved status**

### Counter Management Strategy
Denormalized counters on organizations and locations tables for performance:

**Counter Fields**:
- **organizations.wage_reports_count**: Count of approved wage reports
- **locations.wage_reports_count**: Count of approved wage reports

**Maintenance Approach**:
- **Atomic updates**: Database transactions ensure consistency
- **Underflow protection**: `WHERE wage_reports_count > 0` prevents negatives
- **Status-aware**: Only approved reports count toward totals
- **Observer-driven**: Real-time updates via model events
- **Initialization**: Existing data counted during counter migration

```php
// Atomic counter increment with protection
Location::where('id', $locationId)->increment('wage_reports_count');

// Atomic counter decrement with underflow protection
Location::where('id', $locationId)
    ->where('wage_reports_count', '>', 0)
    ->decrement('wage_reports_count');
```

### Gamification Integration
Automatic experience point awards through observer pattern:

**XP Awards**:
- **Base Submission**: 10 XP for approved wage reports
- **First Report Bonus**: 25 XP for user's first wage report
- **Anonymous Protection**: No XP awarded for anonymous submissions
- **Status-dependent**: Only approved reports earn XP

**Integration Points**:
```php
// Award XP using laravel-level-up package
$user->addPoints(10, null, null, 'wage_report_submitted');
$user->addPoints(25, null, null, 'first_wage_report'); // Bonus
```

### Query Scopes & Patterns

#### Status Filtering Scopes
```php
WageReport::approved()->get();           // status = 'approved'
WageReport::pending()->get();            // status = 'pending'
WageReport::rejected()->get();           // status = 'rejected'
```

#### Spatial Query Scopes
```php
// Find wage reports within radius of coordinates
WageReport::nearby(40.7128, -74.0060, 5000)->get(); // NYC, 5km radius

// Add distance calculation to results
WageReport::withDistance(40.7128, -74.0060)->get();

// Order by distance from point
WageReport::orderByDistance(40.7128, -74.0060)->get();
```

#### Filter Scopes
```php
// Organizational and location filtering
WageReport::forOrganization('mcdonalds')->get();     // By slug
WageReport::forOrganization(123)->get();             // By ID
WageReport::forLocation(456)->get();                 // By location ID

// Date and wage filtering
WageReport::since('2024-01-01')->get();              // Effective date
WageReport::range(1500, 5000)->get();                // Hourly cents range
WageReport::inCurrency('USD')->get();                // Currency filter

// Job and employment filtering
WageReport::byJobTitle('server')->get();             // Job title search
WageReport::byEmploymentType('full_time')->get();    // Employment type
```

### Data Quality & Validation

#### Model-level Validation
```php
// Automatic normalization on create/update
static::creating(function (WageReport $wageReport) {
    // Auto-derive organization from location
    // Calculate normalized hourly cents
    // Observer handles sanity scoring
});

static::updating(function (WageReport $wageReport) {
    // Recalculate if wage fields changed
});
```

#### Business Logic Helpers
```php
// Formatted display methods
$report->normalizedHourlyMoney();        // "$28.85"
$report->originalAmountMoney();          // "$60,000.00"

// Quality assessment helpers
$report->isOutlier();                    // sanity_score < -2
$report->isSuspiciouslyHigh();           // > $100/hour
$report->isSuspiciouslyLow();            // < $7.25/hour (federal minimum)

// Display formatting
$report->employment_type_display;        // "Full Time"
$report->wage_period_display;            // "Yearly" 
$report->status_display;                 // "Approved"
```

### Performance Considerations

#### Index Optimization
- Composite indexes on `(location_id, status)` and `(organization_id, status)` optimize filtered queries
- Separate indexes on searchable fields (job_title, employment_type, etc.)
- Date and wage amount indexes support range queries efficiently

#### Counter Denormalization
- Avoids expensive `COUNT(*)` queries on locations and organizations
- Real-time maintenance through observer pattern
- Atomic database operations prevent race conditions

#### Cache Invalidation Strategy
- Version-based cache keys: `wages:ver`, `orgs:ver`, `locations:ver`
- Observer automatically bumps versions on any wage report change
- Enables aggressive caching with reliable invalidation

## WageReportObserver - Business Logic Engine

### Overview
The WageReportObserver handles all wage report lifecycle events, implementing complex business logic for data quality, counter management, and user engagement.

### Observer Events

#### Creating Event
**Purpose**: Data validation and preprocessing before database storage

**Actions Performed**:
1. **Organization Derivation**: Automatically sets organization_id from location relationship
2. **Wage Normalization**: Calculates normalized_hourly_cents using integer math
3. **Sanity Scoring**: Applies MAD (Median Absolute Deviation) algorithm for outlier detection
4. **Status Assignment**: Sets approved/pending based on sanity score and data quality

**Sanity Scoring Algorithm**:
```sql
-- Location-level statistics (minimum 3 approved reports)
WITH location_stats AS (
    SELECT 
        location_id,
        PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY normalized_hourly_cents) as median_cents,
        AVG(ABS(normalized_hourly_cents - PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY normalized_hourly_cents))) as mad_cents
    FROM wage_reports 
    WHERE location_id = ? AND status = 'approved'
    GROUP BY location_id
    HAVING COUNT(*) >= 3
)
-- If |wage - median| > (K_MAD * mad), mark as outlier
-- K_MAD = 6 (conservative threshold for wage data)
```

#### Created Event
**Purpose**: Post-creation housekeeping and user engagement

**Actions Performed**:
1. **Counter Updates**: Atomically increments wage_reports_count on location and organization
2. **Gamification**: Awards experience points via Level-Up package integration
3. **Cache Invalidation**: Bumps version keys (wages:ver, orgs:ver, locations:ver)
4. **Audit Logging**: Records creation in experience audit trail

**XP Reward Structure**:
- Base reward: 10 XP for approved wage report submission
- First report bonus: 25 XP additional for user's first wage report
- Audit trail: All XP awards logged with reason codes

#### Updated Event
**Purpose**: Handle changes to existing wage reports

**Actions Performed**:
1. **Renormalization**: Recalculates normalized_hourly_cents if wage fields changed
2. **Counter Adjustment**: Updates counters if status changed (approved ↔ pending/rejected)
3. **Cache Invalidation**: Bumps relevant version keys

#### Deleted Event  
**Purpose**: Clean up denormalized data and counters

**Actions Performed**:
1. **Counter Decrement**: Safely decrements counters with underflow protection
2. **Cache Invalidation**: Bumps version keys to invalidate cached data

### Performance Characteristics

**Observer Timing**:
- Creating event: <50ms (including MAD calculation)
- Created event: <25ms (counter updates and XP awards)
- Updated event: <30ms (conditional recalculation)
- Deleted event: <15ms (counter decrements only)

**Database Impact**:
- All counter operations wrapped in database transactions
- Uses single-query PostgreSQL operations where possible
- Optimized indexes support efficient statistical queries
- MAD calculations use window functions for performance

### Counter Update Flow Examples

#### Counter Management Examples

**New Approved Wage Report Created**:
1. User submits wage report for Location A (Organization B)
2. Observer calculates sanity score → approved status
3. Counters updated atomically:
   - `locations[A].wage_reports_count += 1`
   - `organizations[B].wage_reports_count += 1`
4. User awarded 10 XP (+ 25 XP if first report)
5. Cache versions incremented

**Status Changed from Approved → Pending**:
1. Admin marks wage report as pending due to review
2. Observer detects status change
3. Counters decremented:
   - `locations[A].wage_reports_count -= 1`  
   - `organizations[B].wage_reports_count -= 1`
4. Cache versions incremented

### Observer Business Logic Examples

#### Sanity Scoring Example

**Scenario**: Fast food restaurant in NYC
- Location has 25 approved wage reports
- Median wage: $15.00/hour (1500 cents)
- MAD: $1.50/hour (150 cents)
- New submission: $8.00/hour (800 cents)

**Calculation**:
- Deviation: |800 - 1500| = 700 cents
- MAD threshold: 6 × 150 = 900 cents  
- Sanity score: -(700 ÷ 900) = -0.78 → Score: -1
- Result: Still approved (score > -2)

**High Outlier Example**:
- New submission: $45.00/hour (4500 cents)
- Deviation: |4500 - 1500| = 3000 cents
- Sanity score: -(3000 ÷ 900) = -3.33 → Score: -3
- Result: Pending status (requires review)

### MAD Algorithm Implementation

**Statistical Hierarchy**:
1. **Location-level statistics** (≥3 approved reports): Primary choice for most accurate context
2. **Organization-level statistics** (fallback): Broader context when location has insufficient data
3. **Global bounds check** (final fallback): MIN/MAX_HOURLY_CENTS validation

**MAD Calculation Process**:
```php
// Location statistics with optimized PostgreSQL query
$stats = DB::select("
    SELECT 
        COUNT(*) as count,
        PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY normalized_hourly_cents) as median,
        PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY ABS(normalized_hourly_cents - 
            (SELECT PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY normalized_hourly_cents) 
             FROM wage_reports 
             WHERE location_id = ? AND status = 'approved')
        )) as mad
    FROM wage_reports 
    WHERE location_id = ? AND status = 'approved'
", [$locationId, $locationId]);
```

**Scoring Scale Implementation**:
```php
private function calculateMADScore(int $wage, array $stats): int
{
    $median = $stats['median'];
    $mad = $stats['mad'];
    
    // Avoid division by zero
    if ($mad == 0) {
        return $wage == $median ? 5 : 0;
    }
    
    $deviation = abs($wage - $median);
    $madScore = $deviation / $mad;
    
    // Convert to integer score: -5 to 5 scale
    if ($madScore > self::K_MAD) {           // K_MAD = 6
        return -5; // Strong outlier
    } elseif ($madScore > 3) {
        return -2; // Moderate outlier
    } elseif ($madScore > 1.5) {
        return 0;  // Slight concern
    } else {
        return 5;  // Normal range
    }
}
```

### Integration Points

**Level-Up Package Integration**:
```php
// Award XP using laravel-level-up package
private function awardExperiencePoints(WageReport $wageReport): void
{
    if (!$wageReport->user_id || $wageReport->status !== 'approved') {
        return; // Anonymous or non-approved reports don't earn XP
    }
    
    $user = $wageReport->user;
    
    // Base submission reward
    $user->addPoints(10, null, null, 'wage_report_submitted');
    
    // First report bonus
    if ($user->wageReports()->count() === 1) {
        $user->addPoints(25, null, null, 'first_wage_report');
    }
}
```

**Cache Version Management**:
```php
private function bumpCacheVersions(): void
{
    Cache::increment('wages:ver');      // Wage report data changed
    Cache::increment('orgs:ver');       // Organization counters changed  
    Cache::increment('locations:ver');  // Location counters changed
}
```