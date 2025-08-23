# WDTP API Changelog

All notable changes to the WDTP API project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Organizations entity with comprehensive schema and relationships
- Case-insensitive domain uniqueness constraint for organizations
- Organization-location relationship with cascade delete behavior
- Performance counter fields for locations and wage reports counts
- Organization status and verification status management
- Complete database schema documentation in `docs/DATABASE.md`
- Entity relationships documentation in `docs/ENTITIES.md`

### Database Changes

#### Organizations Table (2025-08-23)
- **Migration**: `2025_08_23_202347_create_organizations_table.php`
- **Purpose**: Create organizations table with full schema
- **Key Features**:
  - Case-insensitive unique constraint on domain field using `lower(domain)` index
  - Slug format validation via CHECK constraint (alphanumeric, hyphens, underscores)
  - Foreign key relationships to industries and users with appropriate cascade behaviors
  - Performance counter fields for cached aggregation data
  - Status management for organization lifecycle and verification workflow

#### Organization-Location Relationship (2025-08-23)
- **Migration**: `2025_08_23_202448_add_organization_id_to_locations_table.php`  
- **Purpose**: Link locations to organizations with foreign key relationship
- **Key Features**:
  - Safe migration with existence checks for table and column
  - Cascade delete behavior (deleting organization removes all locations)
  - Performance index on `(organization_id, created_at)` for common queries
  - Proper rollback support with constraint cleanup

#### Technical Implementation Details

**Case-Insensitive Domain Uniqueness**:
```sql
CREATE UNIQUE INDEX organizations_domain_lower_unique 
ON organizations (lower(domain)) 
WHERE domain IS NOT NULL
```
- Prevents domain conflicts regardless of case ("McDonalds.com" vs "mcdonalds.com")
- Partial index only applies when domain is not NULL for efficiency
- Uses PostgreSQL's `lower()` function for normalization

**Slug Format Constraint**:
```sql
ALTER TABLE organizations 
ADD CONSTRAINT check_slug_format 
CHECK (slug ~ '^[a-z0-9][a-z0-9_-]*[a-z0-9]$' OR slug ~ '^[a-z0-9]$')
```
- Enforces URL-safe slug format at database level
- Allows alphanumeric characters, hyphens, and underscores
- Must start and end with alphanumeric characters

**Foreign Key Relationships**:
- `primary_industry_id` → industries (SET NULL on delete, preserves organization)
- `created_by`/`verified_by` → users (SET NULL on delete, preserves organization)  
- `organization_id` in locations → organizations (CASCADE delete, removes all locations)

**Performance Indexes**:
- Standard indexes on name, domain, status flags for filtering
- Composite indexes on `(status, is_active)` and `(verification_status, created_at)`
- Organization-location composite index on `(organization_id, created_at)`

### Migration Rollback Instructions

```bash
# Rollback organization FK from locations table
./vendor/bin/sail artisan migrate:rollback --step=1

# Rollback organizations table creation  
./vendor/bin/sail artisan migrate:rollback --step=1
```

**Warning**: Rolling back will cascade delete all associated locations and wage reports due to foreign key constraints.

---

## Previous Releases

### [0.2.0] - 2025-08-23

#### Added
- Industries API endpoints with hierarchical tree structure
- Industry model with self-referencing parent-child relationships
- Comprehensive industry test coverage (49 tests across 4 test classes)
- API versioning with /api/v1 prefix
- Swagger/OpenAPI documentation for industry endpoints
- Industry caching system with versioned cache keys
- Industry autocomplete endpoint for search functionality

#### Database
- Industries table with hierarchical structure
- Self-referencing foreign key for parent-child relationships
- Full-text search indexes on industry names and descriptions

### [0.1.0] - 2025-08-22

#### Added
- Initial Laravel 12 project setup with Laravel Sail
- External PostgreSQL 17 + PostGIS 3.5 database configuration
- User authentication system with Laravel Sanctum
- PHPUnit testing framework configuration (not Pest)
- Health check endpoints for API monitoring
- User model with role-based authorization structure
- Project documentation and development guidelines

#### Infrastructure
- Laravel Sail containerization for development
- External database connection (no local PostgreSQL)
- PostGIS spatial extension setup
- Laravel Magellan for spatial query support
- Basic API structure with /api/v1 versioning