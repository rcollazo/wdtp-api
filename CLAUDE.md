# CLAUDE.md - WDTP Project Context for Claude Code

## Project Overview
**What Do They Pay? (WDTP)** is a GasBuddy-like platform for wage transparency at brick-and-mortar locations. Users can anonymously submit and search hourly wage data for specific business locations with geographic search capabilities.

## Tech Stack & Architecture
- **Framework**: Laravel 12 with Laravel Sail
- **Language**: PHP 8.3
- **Database**: External PostgreSQL 17 + PostGIS 3.5
- **Authentication**: Laravel Sanctum (token-based API auth)
- **Spatial Queries**: clickbar/laravel-magellan for PostGIS integration
- **Gamification**: cjmellor/laravel-level-up package
- **Testing**: PHPUnit (not Pest)
- **API Design**: RESTful with /api/v1 prefix
- **Container**: Laravel Sail (no local database services)

## External Database Configuration
```env
DB_CONNECTION=pgsql
DB_HOST=10.192.50.3
DB_PORT=5432
DB_DATABASE=wdtp_staging
DB_USERNAME=wdtp_stage_usr
DB_PASSWORD=kZ6-9uwz6H4XZCL8JkiP%
```

## Project Structure & Conventions

### Command Patterns
```bash
# All commands use Sail prefix
./vendor/bin/sail artisan [command]
./vendor/bin/sail composer [command]
./vendor/bin/sail test [options]

# No local PostgreSQL installation - using external server
# Spatial queries use geography(Point,4326) with GiST indexes
```

### Core Domain Models

#### Industries
- Hierarchical categorization (Food Service, Retail, Healthcare, etc.)
- Self-referencing parent_id for subcategories
- Used to organize organizations and position categories

#### Organizations
- Business entities (McDonald's, Walmart, etc.)
- Belong to industry, have multiple locations
- Full-text search on name/description

#### Locations
- Physical addresses with PostGIS coordinates
- geography(Point,4326) for spatial queries
- Belong to organizations, have wage reports

#### Wage Reports
- Anonymous hourly wage submissions
- Status workflow: pending → approved/rejected/flagged
- Moderated by admin/moderator roles
- Support voting (helpful/not helpful) and flagging

#### Gamification System
- User levels, experience points, achievements
- Points awarded for: submitting reports, getting approved, helpful votes
- Achievements: First Report, Helpful Contributor, Location Scout, etc.

### API Endpoints Structure

```
/api/v1/
├── auth/
│   ├── register, login, logout, me
│   └── leaderboard
├── industries/
│   ├── GET / (list with subcategories)
│   └── GET /{id} (show with organization count)
├── organizations/
│   ├── GET / (search: name, industry, location, verified status)
│   ├── POST / (auth: contributor+)
│   ├── GET /{id}/locations
│   └── GET /{id}/wage-reports
├── locations/
│   ├── GET / (spatial: near=lat,lon&radius_km=, filters)
│   ├── POST / (auth: contributor+)
│   ├── GET /{id}
│   └── GET /{id}/wage-reports
├── wage-reports/
│   ├── POST / (public, creates pending)
│   ├── GET / (approved only, spatial + filters)
│   ├── GET /{id}
│   ├── POST /{id}/vote (auth required)
│   ├── POST /{id}/flag (auth required)
│   ├── PATCH /{id}/approve (moderator+)
│   └── PATCH /{id}/reject (moderator+)
├── position-categories/
│   ├── GET / (by industry)
│   └── GET /{id}
├── stats/
│   ├── GET /overview
│   ├── GET /industries
│   └── GET /trends
└── healthz/ (health checks)
```

### Spatial Query Patterns

#### Distance-based Search
```php
// Filter pattern
ST_DWithin(locations.point, ST_SetSRID(ST_MakePoint(:lon,:lat),4326)::geography, :meters)

// Distance calculation  
ST_Distance(locations.point, ST_SetSRID(ST_MakePoint(:lon,:lat),4326)::geography) AS distance_meters

// Query parameters
?near=40.7128,-74.0060&radius_km=5
```

#### Required Response Format
- Include `distance_meters` in API responses when `near` parameter provided
- Use Laravel API Resources to hide internal fields
- Pagination for list endpoints
- Consistent error response format

### Authentication & Authorization

#### User Roles
- **viewer**: Default role, can submit wage reports
- **contributor**: Can create organizations and locations  
- **moderator**: Can approve/reject wage reports
- **admin**: Full access, can verify organizations

#### Token Authentication
- Laravel Sanctum personal access tokens
- Non-expiring tokens for API access
- Rate limiting on auth endpoints (5/minute)
- Rate limiting on submissions (10/minute)

### Testing Requirements

#### PHPUnit Standards
```php
// Test each feature as implemented
./vendor/bin/sail test --filter=TestName

// Required test types:
- Feature tests for API endpoints
- Unit tests for models and services  
- Spatial query tests with real coordinates
- Authentication flow tests
- Gamification system tests
```

#### Test Data Patterns
```php
// Use factories for consistent test data
- Industry::factory()->create()
- Location::factory()->withCoordinates($lat, $lon)->create()
- WageReport::factory()->approved()->create()

// Test spatial queries with realistic US coordinates
- NYC: 40.7128, -74.0060
- LA: 34.0522, -118.2437
- Chicago: 41.8781, -87.6298
```

### Database Design Patterns

#### Migration Conventions
```php
// PostGIS setup
DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

// Geography columns with indexes
$table->geography('point', 'POINT', 4326);
DB::statement('CREATE INDEX locations_point_gist_idx ON locations USING GIST (point)');

// Status enums
$table->enum('status', ['pending', 'approved', 'rejected', 'flagged'])->default('pending');
$table->enum('role', ['admin', 'moderator', 'contributor', 'viewer'])->default('viewer');
```

#### Model Relationships
```php
// Standard patterns
public function industry(): BelongsTo
public function locations(): HasMany
public function wageReports(): HasMany
public function interactions(): HasMany

// Spatial scopes
public function scopeNear($query, $lat, $lon, $radiusKm = 10)
public function scopeWithDistance($query, $lat, $lon)
```

### Validation & Business Rules

#### Duplicate Prevention
- Same user + location + position within 30 days = duplicate
- Check before creating new wage reports

#### Rate Limiting
```php
// Named limiters
'submit' => '10,1', // 10 requests per minute for submissions
'auth' => '5,1',    // 5 requests per minute for auth

// Usage
Route::middleware('throttle:submit')->group(...)
```

#### Required Validations
- Coordinate validation (-90 to 90 lat, -180 to 180 lon)
- Wage amount: positive decimal with 2 places
- Status transitions: only moderators can approve/reject
- File restrictions per user role

### Development Workflow

#### Conventional Commits
```
feat(auth): implement Sanctum authentication
fix(spatial): correct distance calculation in location search  
test(wage-reports): add comprehensive validation tests
docs(api): update OpenAPI specifications
```

#### Key Implementation Order
1. Foundation: Sail + PostGIS + Health checks
2. Auth: Enhanced User model + Sanctum
3. Domain: Industries → Organizations → Locations → Positions
4. Core: Wage Reports with validation + spatial search
5. Interactions: Voting/flagging + moderation workflow
6. Gamification: Level-up integration + achievements
7. Polish: Analytics + documentation + CI/CD

### Error Handling Patterns

#### Standard Error Responses
```json
{
  "message": "Validation failed",
  "errors": {
    "wage_amount": ["The wage amount must be a positive number"]
  }
}
```

#### Spatial Query Errors
- Invalid coordinates: 422 with clear message
- No results within radius: 200 with empty array
- PostGIS errors: 500 with generic message (hide internals)

### Security Considerations

#### Data Protection
- Hide internal fields (review_notes, etc.) from public API responses
- Validate all geographic inputs to prevent injection
- Rate limit submissions to prevent spam
- Audit log all moderation actions

#### File Access Restrictions
- Public endpoints: wage reports (approved only), industries, organizations
- Auth required: voting, flagging, profile access
- Role-based: moderation, organization verification
- Spatial data: always validate coordinate bounds

### Performance Optimization

#### Database Indexes
- GiST index on geography columns
- Composite indexes on (status, created_at) for wage reports
- Full-text search indexes on organization name/description
- Foreign key indexes on all relationship columns

#### Caching Strategy
- Cache popular search queries (Redis when available)
- Cache analytics queries (daily/hourly refresh)
- Cache industry/position category lists
- Cache user leaderboard data

This document provides the complete context for Claude Code to understand the WDTP project structure, conventions, and requirements for effective development assistance.
