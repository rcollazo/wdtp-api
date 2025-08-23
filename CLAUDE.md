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
- Add useful array shape type definitions for arrays when appropriate.

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