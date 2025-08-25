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