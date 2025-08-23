# Database Schema Documentation

This document provides comprehensive documentation of the WDTP database schema, relationships, and performance considerations.

## Tech Stack
- **Database**: PostgreSQL 17 + PostGIS 3.5 (External server)
- **ORM**: Laravel 12 Eloquent
- **Spatial Extension**: PostGIS with Laravel Magellan
- **Connection**: External database only (no local PostgreSQL)

## Table Schemas

### Organizations

The `organizations` table manages business entities (companies, franchises, etc.) that have multiple locations where wage reports are submitted.

#### Table Structure

```sql
CREATE TABLE organizations (
    -- Primary key
    id BIGSERIAL PRIMARY KEY,
    
    -- Core identification
    name VARCHAR(160) NOT NULL,
    slug VARCHAR(160) UNIQUE NOT NULL,
    legal_name VARCHAR(200),
    website_url VARCHAR(200),
    domain VARCHAR(120),
    description TEXT,
    logo_url VARCHAR(300),
    
    -- Industry relationship
    primary_industry_id BIGINT REFERENCES industries(id) ON DELETE SET NULL,
    
    -- Status management
    status organization_status DEFAULT 'active' NOT NULL,
    verification_status organization_verification_status DEFAULT 'unverified' NOT NULL,
    
    -- User relationships for attribution
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    verified_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    verified_at TIMESTAMP,
    
    -- Performance counters (maintained by application)
    locations_count INTEGER DEFAULT 0 NOT NULL,
    wage_reports_count INTEGER DEFAULT 0 NOT NULL,
    
    -- Display flags
    is_active BOOLEAN DEFAULT true NOT NULL,
    visible_in_ui BOOLEAN DEFAULT true NOT NULL,
    
    -- Timestamps
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- Enum types
CREATE TYPE organization_status AS ENUM ('active', 'inactive', 'suspended');
CREATE TYPE organization_verification_status AS ENUM ('unverified', 'pending', 'verified', 'rejected');
```

#### Column Descriptions

| Column | Type | Constraints | Purpose |
|--------|------|-------------|---------|
| `id` | BIGSERIAL | PRIMARY KEY | Auto-incrementing primary key |
| `name` | VARCHAR(160) | NOT NULL, INDEXED | Display name of organization |
| `slug` | VARCHAR(160) | UNIQUE, NOT NULL | URL-safe identifier |
| `legal_name` | VARCHAR(200) | NULLABLE | Official registered business name |
| `website_url` | VARCHAR(200) | NULLABLE | Primary website URL |
| `domain` | VARCHAR(120) | NULLABLE, INDEXED | Primary email/website domain |
| `description` | TEXT | NULLABLE | Organization description |
| `logo_url` | VARCHAR(300) | NULLABLE | URL to organization logo |
| `primary_industry_id` | BIGINT | FK → industries, SET NULL | Main industry classification |
| `status` | ENUM | DEFAULT 'active' | Active/inactive/suspended state |
| `verification_status` | ENUM | DEFAULT 'unverified' | Verification workflow state |
| `created_by` | BIGINT | FK → users, SET NULL | User who created organization |
| `verified_by` | BIGINT | FK → users, SET NULL | User who verified organization |
| `verified_at` | TIMESTAMP | NULLABLE | When verification occurred |
| `locations_count` | INTEGER | DEFAULT 0 | Cached count of organization locations |
| `wage_reports_count` | INTEGER | DEFAULT 0 | Cached count of wage reports |
| `is_active` | BOOLEAN | DEFAULT true, INDEXED | Whether organization is active |
| `visible_in_ui` | BOOLEAN | DEFAULT true, INDEXED | Whether to show in UI |

#### Indexes and Performance

```sql
-- Primary and unique indexes
PRIMARY KEY (id)
UNIQUE INDEX organizations_slug_unique (slug)

-- Standard indexes for filtering
INDEX organizations_name_index (name)
INDEX organizations_domain_index (domain)
INDEX organizations_is_active_index (is_active)
INDEX organizations_visible_in_ui_index (visible_in_ui)

-- Composite indexes for common queries
INDEX organizations_status_is_active_index (status, is_active)
INDEX organizations_verification_status_created_at_index (verification_status, created_at)

-- Case-insensitive unique constraint on domain
UNIQUE INDEX organizations_domain_lower_unique (lower(domain)) WHERE domain IS NOT NULL
```

#### Constraints

1. **Case-Insensitive Domain Uniqueness**
   ```sql
   CREATE UNIQUE INDEX organizations_domain_lower_unique 
   ON organizations (lower(domain)) 
   WHERE domain IS NOT NULL
   ```
   - Prevents duplicate domains regardless of case (e.g., "McDonald's.com" vs "mcdonalds.com")
   - Partial index only applies when domain is not NULL for efficiency
   - Uses PostgreSQL's `lower()` function for case normalization

2. **Slug Format Validation**
   ```sql
   ALTER TABLE organizations 
   ADD CONSTRAINT check_slug_format 
   CHECK (slug ~ '^[a-z0-9][a-z0-9_-]*[a-z0-9]$' OR slug ~ '^[a-z0-9]$')
   ```
   - Enforces URL-safe slug format
   - Allows alphanumeric characters, hyphens, and underscores
   - Must start and end with alphanumeric characters
   - Single character slugs are allowed

#### Foreign Key Relationships

1. **Organization → Industry** (`primary_industry_id`)
   - **Type**: Optional many-to-one relationship
   - **Cascade**: `ON DELETE SET NULL`
   - **Purpose**: Categorize organizations by primary industry
   - **Rationale**: Organizations can exist without industry classification

2. **Organization ← Locations** (`organization_id` in locations table)
   - **Type**: One-to-many relationship
   - **Cascade**: `ON DELETE CASCADE`
   - **Purpose**: Organizations have multiple physical locations
   - **Performance**: Indexed with `(organization_id, created_at)`

3. **Organization → Users** (Attribution fields)
   - **`created_by`**: User who created the organization record
   - **`verified_by`**: User who verified the organization (admin/moderator)
   - **Cascade**: `ON DELETE SET NULL` (preserve organization data)

#### Status Management

**Organization Status** (`status` field):
- `active`: Organization is operational and accepting wage reports
- `inactive`: Organization exists but is not currently active
- `suspended`: Organization temporarily disabled by moderators

**Verification Status** (`verification_status` field):
- `unverified`: Default state for user-created organizations
- `pending`: Organization submitted for verification review
- `verified`: Organization confirmed as legitimate by moderators
- `rejected`: Organization verification was denied

#### Counter Maintenance

Performance counters are maintained by application-level logic:

```php
// Automatically updated via Eloquent events or jobs
$organization->locations_count = $organization->locations()->count();
$organization->wage_reports_count = $organization->locations()->withCount('wageReports')->sum('wage_reports_count');
```

#### Migration Rollback Instructions

```bash
# Rollback organization FK from locations table
./vendor/bin/sail artisan migrate:rollback --step=1

# Rollback organizations table creation
./vendor/bin/sail artisan migrate:rollback --step=1
```

**Note**: Rolling back will cascade delete all associated locations and wage reports due to foreign key constraints.

#### Query Examples

```php
// Case-insensitive domain search
Organization::whereRaw('lower(domain) = ?', [strtolower($searchDomain)])->first();

// Active organizations with locations in specific industry
Organization::where('status', 'active')
    ->where('is_active', true)
    ->where('primary_industry_id', $industryId)
    ->has('locations')
    ->with('primaryIndustry')
    ->paginate(20);

// Organizations requiring verification
Organization::where('verification_status', 'pending')
    ->with(['createdBy', 'primaryIndustry'])
    ->orderBy('created_at')
    ->get();
```

## Entity Relationships Overview

```
industries (1) ← primary_industry_id ← organizations (many)
users (1) ← created_by/verified_by ← organizations (many)
organizations (1) → organization_id → locations (many)
locations (many) → wage_reports (many) [indirect relationship]
```

## Performance Considerations

1. **Domain Lookups**: Case-insensitive unique index prevents domain conflicts
2. **Status Filtering**: Composite indexes optimize common status + active queries
3. **Counter Fields**: Avoid expensive COUNT queries on large datasets
4. **Partial Indexes**: Domain uniqueness only applies to non-NULL values
5. **Cascade Deletes**: Carefully managed to preserve data integrity

## Migration History

- `2025_08_23_202347_create_organizations_table.php` - Initial organizations table
- `2025_08_23_202448_add_organization_id_to_locations_table.php` - Link locations to organizations