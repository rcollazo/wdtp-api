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

### MCP Integration
- **Laravel Boost MCP**: Available for advanced Laravel operations
- **Installation**: Installed as dev dependency in main project
- **Capabilities**: Code generation, optimization, refactoring assistance
- **Context Aware**: Has full access to WDTP models, migrations, and structure

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
- Physical addresses with PostGIS coordinates and dual storage architecture
- geography(Point,4326) for spatial queries + cached lat/lng for quick access
- Comprehensive spatial scopes: `near()`, `withDistance()`, `orderByDistance()`
- Full-text search capabilities on name, address, and city
- GiST spatial indexes for optimal PostGIS query performance (<200ms requirement)
- Route model binding support (ID and slug resolution)
- Automatic PostGIS point updates via model events
- Belong to organizations, have wage reports (when implemented)

#### Position Categories
- Job position classifications within industries (Server, Cashier, Manager, etc.)
- Belong to industries with unique names per industry constraint
- Auto-generated slugs with route model binding (ID and slug resolution)
- Full-text search on name and description fields
- Status workflow: active/inactive with default active filtering
- Comprehensive caching for API endpoints (5-minute cache TTL)
- Used for wage report categorization and industry-specific position filtering

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
│   ├── GET / (hierarchical tree or flat list)
│   ├── GET /autocomplete (search suggestions) 
│   └── GET /{idOrSlug} (show industry by ID or slug)
├── organizations/
│   ├── GET / (search: name, industry, location, verified status)
│   ├── POST / (auth: contributor+)
│   ├── GET /{id}/locations
│   ├── GET /{id}/wage-reports (organization wage reports)
│   └── GET /{id}/wage-statistics (org-wide statistics)
├── locations/
│   ├── GET / (spatial: near=lat,lon&radius_km=, filters)
│   ├── POST / (auth: contributor+)
│   ├── GET /{id}
│   └── GET /{id}/wage-reports (location-specific reports)
├── wage-reports/
│   ├── POST / (anonymous & auth submissions, rate limited)
│   ├── GET / (approved only, spatial search + comprehensive filters)
│   ├── GET /{id} (individual report with relationships)
│   ├── GET /statistics (global wage statistics with percentiles)
│   ├── POST /{id}/vote (auth required) [PLANNED]
│   ├── POST /{id}/flag (auth required) [PLANNED]
│   ├── PATCH /{id}/approve (moderator+) [PLANNED]
│   └── PATCH /{id}/reject (moderator+) [PLANNED]
├── position-categories/
│   ├── GET / (by industry, search, status filtering, pagination)
│   ├── GET /autocomplete (fast search with minimal payload)
│   └── GET /{idOrSlug} (show position by ID or slug)
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

### Testing Requirements

**📋 For comprehensive testing documentation, see [docs/TESTING.md](docs/TESTING.md)**

#### Current Test Status
- **449+ passing tests** with **98.5% pass rate** (approximately 10 failed)
- **Total: 643+ test methods across 42 test classes**
- User model fully tested (12 tests)
- Industry model comprehensively tested (49 tests across 4 test classes)
- Organization model comprehensively tested (31 tests across 2 test classes)
- Health check endpoints tested (2 tests)
- Authentication flow fully tested (13 tests)
- Industry API endpoints fully tested (28 tests across 2 test classes)
- Organization API endpoints fully tested (23 tests with caching)
- Location model comprehensively tested (72 tests across 5 test suites)
  - Database migrations with PostGIS testing (10 tests)
  - Model functionality and spatial scopes (25 tests)
  - Factory patterns and data generation (18 tests) 
  - Spatial query accuracy and performance (9 tests)
  - Seeder functionality with US cities (15 tests)
- Position Categories comprehensively tested (119 tests across 7 test classes)
  - Model functionality and relationships (25 tests)
  - Database constraints and integrity (12 tests) 
  - Security and input validation (15 tests)
  - API endpoints with caching (33 tests)
  - Factory patterns and data generation (17 tests)
  - Performance and scaling (15 tests)
  - Resource transformation (10 tests)
- Wage Reports comprehensively tested (149+ tests across 7 test classes)
  - WageReport model with normalization and relationships (31 tests)
  - WageReport observer and counter management (52 tests)
  - Wage report API endpoints with spatial search (18 tests)  
  - Wage statistics API with PostgreSQL percentiles (19 tests)
  - Wage report creation with validation and rate limiting (tests)
  - PostGIS spatial query accuracy (±25m tolerance validated)
  - Performance benchmarks (<200ms spatial queries, <500ms API responses)
- Database migrations, factories, and seeders working
- Swagger/OpenAPI documentation tested (6 tests)

#### PHPUnit Standards
```bash
# Test each feature as implemented
./vendor/bin/sail test --filter=TestName

# Run all tests (currently 449+ passing with 98.5% pass rate)
./vendor/bin/sail test

# Run specific test suites
./vendor/bin/sail test --testsuite=Unit
./vendor/bin/sail test --testsuite=Feature
```

#### Required Test Types
- Feature tests for API endpoints
- Unit tests for models and services
- Spatial query tests with real coordinates
- Authentication flow tests
- Gamification system tests

#### Test Data Patterns
```php
// Use factories for consistent test data
- Industry::factory()->create()
- Organization::factory()->active()->verified()->create()
- Location::factory()->withCoordinates($lat, $lon)->create()
- Location::factory()->inCity('New York')->active()->create()
- PositionCategory::factory()->active()->create()
- PositionCategory::factory()->foodService()->create() // Industry-specific states
- WageReport::factory()->approved()->create() // When implemented

// Location factory states and city helpers
- Location::factory()->active()->verified()->create()
- Location::factory()->newYork()->create()
- Location::factory()->losAngeles()->create()
- Location::factory()->chicago()->create()

// Test spatial queries with realistic US coordinates (±500m tolerance)
- NYC: 40.7128, -74.0060
- LA: 34.0522, -118.2437  
- Chicago: 41.8781, -87.6298
- Houston: 29.7604, -95.3698
- Phoenix: 33.4484, -112.0740
```

### Database Design Patterns

#### Migration Conventions
```php
// PostGIS setup (with existence check)
DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

// Dual coordinate storage for optimal performance
$table->decimal('latitude', 10, 8);   // Cached for quick access
$table->decimal('longitude', 11, 8);  // Cached for sorting

// PostGIS geography column for spatial queries
DB::statement('ALTER TABLE locations ADD COLUMN point GEOGRAPHY(POINT, 4326)');
DB::statement('CREATE INDEX locations_point_gist_idx ON locations USING GIST (point)');

// Full-text search index for location search
DB::statement('CREATE INDEX locations_name_address_city_fulltext 
    ON locations USING gin(to_tsvector(\'english\', 
    coalesce(name, \'\') || \' \' || 
    coalesce(address_line_1, \'\') || \' \' || 
    coalesce(city, \'\')))');

// Status enums
$table->enum('status', ['pending', 'approved', 'rejected', 'flagged'])->default('pending');
$table->enum('role', ['admin', 'moderator', 'contributor', 'viewer'])->default('viewer');
$table->enum('position_status', ['active', 'inactive'])->default('active');

// Position Categories constraints
$table->unique(['name', 'industry_id']); // Unique position names per industry
$table->unique(['slug']); // Global slug uniqueness
$table->index(['industry_id', 'status']); // Filtered queries
$table->index(['name']); // Search performance
```

#### Model Relationships
```php
// Standard patterns
public function industry(): BelongsTo
public function locations(): HasMany
public function wageReports(): HasMany
public function interactions(): HasMany

// Location model spatial scopes and relationships
public function organization(): BelongsTo
public function wageReports(): HasMany // Ready for implementation

// Position Categories model relationships and scopes
public function industry(): BelongsTo
public function wageReports(): HasMany // Ready for implementation

// Position Categories scopes
public function scopeActive($query): Builder
public function scopeForIndustry($query, $industryId): Builder
public function scopeSearch($query, $term): Builder

// Location spatial scopes (PostGIS powered)
public function scopeNear($query, $lat, $lon, $radiusKm = 10): Builder
public function scopeWithDistance($query, $lat, $lon): Builder
public function scopeOrderByDistance($query, $lat, $lon): Builder

// Location search and filter scopes
public function scopeSearch($query, $term): Builder
public function scopeInCity($query, $city): Builder
public function scopeInState($query, $state): Builder
public function scopeActive($query): Builder
public function scopeVerified($query): Builder
```

### Validation & Business Rules

#### Duplicate Prevention
- Same user + location + position within 30 days = duplicate
- Check before creating new wage reports

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

**IMPORTANT**: Never include AI/Claude references in commit messages. Keep commits professional and focused on the actual changes made.

#### Key Implementation Order
1. COMPLETE: Foundation: Sail + PostGIS + Health checks
2. COMPLETE: Auth: Enhanced User model + Sanctum
3. COMPLETE: Industries (with API, tests, seeder)
4. COMPLETE: Organizations (API with index, show, autocomplete endpoints)
5. COMPLETE: Locations (spatial model with PostGIS integration and comprehensive testing) 
6. COMPLETE: Position Categories (API endpoints, caching, comprehensive testing)
7. TODO: Core: Wage Reports with validation + spatial search
8. TODO: Interactions: Voting/flagging + moderation workflow
9. TODO: Gamification: Level-up integration + achievements
10. TODO: Polish: Analytics + documentation + CI/CD

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
- Audit log all moderation actions

#### File Access Restrictions
- Public endpoints: wage reports (approved only), industries, organizations
- Auth required: voting, flagging, profile access
- Role-based: moderation, organization verification
- Spatial data: always validate coordinate bounds

### Performance Optimization

#### Database Indexes
- GiST index on geography columns for spatial queries
- Composite indexes on (status, created_at) for wage reports
- Full-text search indexes on organization name/description AND location search
- Foreign key indexes on all relationship columns
- Coordinate indexes on (latitude, longitude) for quick sorting

#### Location Model Spatial Performance
- All spatial queries must complete within 200ms (tested requirement)
- GiST index utilization verified for PostGIS operations
- Distance calculations accurate to ±25m (tested tolerance)
- Dual storage: geography column for accuracy + lat/lng for performance

#### Caching Strategy
- Cache popular search queries (Redis when available)
- Cache analytics queries (daily/hourly refresh)
- Cache industry/position category lists
- Cache user leaderboard data

This document provides the complete context for Claude Code to understand the WDTP project structure, conventions, and requirements for effective development assistance.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to enhance the user's satisfaction building Laravel applications.

## Foundational Context
This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4.11
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- laravel/pint (PINT) - v1


## Conventions
- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts
- Do not create verification scripts or tinker when tests cover that functionality and prove it works. Unit and feature tests are more important.

## Application Structure & Architecture
- Stick to existing directory structure - don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling
- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Replies
- Be concise in your explanations - focus on what's important rather than explaining obvious details.

## Documentation Files
- You must only create documentation files if explicitly requested by the user.


=== boost rules ===

## Laravel Boost
- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan
- Use the `list-artisan-commands` tool when you need to call an Artisan command to double check the available parameters.

## URLs
- Whenever you share a project URL with the user you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain / IP, and port.

## Tinker / Debugging
- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool
- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)
- Boost comes with a powerful `search-docs` tool you should use before any other approaches. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation specific for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- The 'search-docs' tool is perfect for all Laravel related packages, including Laravel, Inertia, Livewire, Filament, Tailwind, Pest, Nova, Nightwatch, etc.
- You must use this tool to search for Laravel-ecosystem documentation before falling back to other approaches.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic based queries to start. For example: `['rate limiting', 'routing rate limiting', 'routing']`.
- Do not add package names to queries - package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax
- You can and should pass multiple queries at once. The most relevant results will be returned first.

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit"
3. Quoted Phrases (Exact Position) - query="infinite scroll" - Words must be adjacent and in that order
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit"
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms


=== php rules ===

## PHP

- Always use curly braces for control structures, even if it has one line.

### Constructors
- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters.

### Type Declarations
- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Comments
- Prefer PHPDoc blocks over comments. Never use comments within the code itself unless there is something _very_ complex going on.

## PHPDoc Blocks
- Always add useful array shape type definitions for arrays.

## Enums
- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.


=== laravel/core rules ===

## Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Database
- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation
- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources
- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

### Controllers & Validation
- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

### Queues
- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

### Authentication & Authorization
- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

### URL Generation
- When generating links to other pages, prefer named routes and the `route()` function.

### Configuration
- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

### Testing
- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] <name>` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

### Vite Error
- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.


=== laravel/v12 rules ===

## Laravel 12

- Use the `search-docs` tool to get version specific documentation.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

### Laravel 12 Structure
- No middleware files in `app/Http/Middleware/`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- **No app\Console\Kernel.php** - use `bootstrap/app.php` or `routes/console.php` for console configuration.
- **Commands auto-register** - files in `app/Console/Commands/` are automatically available and do not require manual registration.

### Database
- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 11 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models
- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.


=== pint/core rules ===

## Laravel Pint Code Formatter

- You must run `vendor/bin/pint --dirty` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test`, simply run `vendor/bin/pint` to fix any formatting issues.
</laravel-boost-guidelines>
