# WDTP Testing Guide

## Overview
This guide covers testing practices, patterns, and conventions for the **What Do They Pay (WDTP)** platform. We use PHPUnit for all testing and follow Laravel's testing conventions.

## Running Existing Tests

### Current Test Suite Status
The WDTP project currently has **21 test classes** with **221 passing tests** and **2328 assertions**:

**Unit Tests:**
- `ExampleTest` - Basic PHPUnit example test (1 test)
- `IndustryConstraintsTest` - Database constraints and integrity testing (16 tests)
- `IndustryObserverTest` - Industry model observer and lifecycle testing (14 tests) 
- `IndustryResourceTest` - API resource transformation testing (9 tests)
- `IndustryTest` - Industry model functionality testing (10 tests)  
- `LocationMigrationTest` - PostGIS migration and schema testing (10 tests)
- `LocationTest` - Location model functionality and spatial scopes (25 tests)
- `LocationFactoryTest` - Location factory patterns and data generation (18 tests)
- `OrganizationResourceTest` - Organization API resource testing (8 tests)
- `UserModelTest` - Comprehensive User model testing (12 tests)

**Feature Tests:**
- `AdminSeederTest` - Admin user seeding functionality (4 tests)
- `AuthenticationTest` - Complete authentication flow testing (13 tests)
- `ExampleTest` - Basic Laravel application test (1 test)
- `HealthCheckTest` - API health check endpoints (2 tests)
- `IndustryApiTest` - Industries API endpoints comprehensive testing (15 tests)
- `IndustryEdgeCasesTest` - Edge cases and error handling for industries (13 tests)
- `IndustrySeederTest` - Industry taxonomy seeding functionality (6 tests)
- `LocationSpatialTest` - PostGIS spatial query accuracy and performance (9 tests)
- `LocationSeederTest` - Location seeding with US cities data (15 tests)
- `OrganizationApiTest` - Organizations API endpoints comprehensive testing (23 tests)
- `SwaggerDocumentationTest` - API documentation and OpenAPI testing (6 tests)

### Running Tests

```bash
# Run all tests (221 tests currently)
./vendor/bin/sail test

# Run specific test suite
./vendor/bin/sail test --testsuite=Unit      # 142 tests (includes Location model tests)
./vendor/bin/sail test --testsuite=Feature   # 107 tests (includes Location spatial/seeder tests)

# Run specific test class
./vendor/bin/sail test --filter=LocationTest
./vendor/bin/sail test tests/Unit/Models/LocationTest.php
./vendor/bin/sail test tests/Feature/LocationSpatialTest.php
./vendor/bin/sail test tests/Unit/Database/LocationMigrationTest.php
./vendor/bin/sail test tests/Unit/Factories/LocationFactoryTest.php
./vendor/bin/sail test tests/Feature/LocationSeederTest.php

# Run Location-related tests only
./vendor/bin/sail test --filter=Location

# Run specific test method
./vendor/bin/sail test --filter=test_near_scope_finds_locations_within_radius
./vendor/bin/sail test --filter=test_spatial_query_performance

# Run tests with detailed output
./vendor/bin/sail test --verbose
```

### Current Test Results
When you run `./vendor/bin/sail test`, you should see:
```
PASS  Tests\Unit\ExampleTest
✓ that true is true

PASS  Tests\Unit\UserModelTest  
✓ user fillable attributes
✓ user hidden attributes
✓ user casts attributes
✓ user creation with wdtp fields
✓ user role validation
✓ user default values
✓ user factory creates valid user
✓ user factory role states
✓ user factory enabled states
✓ username uniqueness
✓ nullable fields
✓ required fields not null

PASS  Tests\Feature\ExampleTest
✓ the application returns a successful response

PASS  Tests\Feature\HealthCheckTest
✓ basic health check
✓ deep health check

PASS  Tests\Feature\AuthenticationTest
✓ user can register successfully
✓ user cannot register with invalid data
✓ user cannot register with duplicate email
✓ user cannot register with duplicate username
✓ user can login with valid credentials
✓ user cannot login with invalid credentials
✓ user cannot login when disabled
✓ user can logout successfully
✓ unauthenticated user cannot logout
✓ authenticated user can access profile
✓ unauthenticated user cannot access profile
✓ complete authentication flow works
✓ invalid token returns unauthorized

PASS  Tests\Feature\AdminSeederTest
✓ creates admin user with environment variables
✓ creates admin user with default credentials
✓ prevents duplicate admin creation
✓ creates admin with proper role and enabled status

Tests:    221 passed (2328 assertions)  
Duration: ~27.9s
```

## Existing Test Details

### Unit Tests

#### 1. ExampleTest (`tests/Unit/ExampleTest.php`)
Basic PHPUnit example test that verifies the testing framework works.
```php
public function test_that_true_is_true(): void
{
    $this->assertTrue(true);
}
```

#### 2. UserModelTest (`tests/Unit/UserModelTest.php`)
Comprehensive testing of the User model with WDTP-specific fields:

**Tests Coverage:**
- ✅ Fillable attributes validation
- ✅ Hidden attributes (password, remember_token)
- ✅ Attribute casting (birthday → Carbon, enabled → boolean)
- ✅ User creation with all WDTP fields
- ✅ Role system validation (admin, moderator, contributor, viewer)
- ✅ Default values (role: viewer, enabled: false)
- ✅ Factory functionality and states
- ✅ Username uniqueness constraint
- ✅ Nullable field handling (phone, birthday)
- ✅ Required field validation

### Feature Tests

#### 1. ExampleTest (`tests/Feature/ExampleTest.php`)
Basic Laravel application test that verifies the welcome page loads.
```php
public function test_the_application_returns_a_successful_response(): void
{
    $response = $this->get('/');
    $response->assertStatus(200);
}
```

#### 2. HealthCheckTest (`tests/Feature/HealthCheckTest.php`)
Tests the API health check endpoints:

**Basic Health Check** (`/api/v1/healthz`)
- Verifies the API is responsive
- Returns 200 status

**Deep Health Check** (`/api/v1/healthz/deep`)
- Tests database connectivity
- Tests PostGIS extension availability
- Returns JSON with 'database' and 'postgis' status

#### 3. AuthenticationTest (`tests/Feature/AuthenticationTest.php`)
Comprehensive testing of the authentication system with 13 tests covering:

**Registration Tests:**
- ✅ Successful user registration with valid data
- ✅ Registration validation (email/username required, password confirmation)
- ✅ Duplicate email prevention
- ✅ Duplicate username prevention

**Login Tests:**
- ✅ Successful login with valid credentials
- ✅ Login failure with invalid credentials
- ✅ Login prevention for disabled users

**Logout Tests:**
- ✅ Successful token revocation on logout
- ✅ Unauthorized logout attempt handling

**Profile Access Tests:**
- ✅ Authenticated user can access profile endpoint
- ✅ Unauthenticated user cannot access profile

**Integration Tests:**
- ✅ Complete authentication flow (register → login → profile → logout)
- ✅ Invalid token handling

#### 4. AdminSeederTest (`tests/Feature/AdminSeederTest.php`)
Tests the admin user seeding functionality with 4 tests:

**Environment Variable Tests:**
- ✅ Creates admin user using `SEED_ADMIN_EMAIL` and `SEED_ADMIN_PASSWORD`
- ✅ Falls back to default credentials when environment variables not set

**Data Integrity Tests:**
- ✅ Prevents duplicate admin user creation
- ✅ Ensures admin has proper role ('admin') and enabled status (true)

#### 5. OrganizationApiTest (`tests/Feature/OrganizationApiTest.php`)
Comprehensive testing of the Organizations API endpoints with 23 tests covering:

**Index Endpoint Tests:**
- ✅ Returns paginated organizations list with proper structure
- ✅ Applies default filters (active, visible, approved status)
- ✅ Search functionality across name, legal_name, and domain fields
- ✅ Industry filtering by ID and slug (mutually exclusive)
- ✅ Verification status filtering with boolean conversion
- ✅ Location presence filtering (has_locations parameter)
- ✅ Multiple sort options: name, locations, wage_reports, relevance
- ✅ Pagination parameters and limits validation
- ✅ Query parameter validation and error responses

**Show Endpoint Tests:**
- ✅ Returns organization by numeric ID
- ✅ Returns organization by slug with case-sensitive matching
- ✅ Includes primary industry relationship with eager loading
- ✅ Applies default filters for access control
- ✅ Returns 404 for non-existent organizations

**Caching System Tests:**
- ✅ Index endpoint caching with parameter-based cache keys
- ✅ Show endpoint caching with ID/slug-based cache keys
- ✅ Cache key slug resolution and consistency
- ✅ Cache version system with automatic invalidation

**Performance and Integration:**
- All tests use factories for consistent data setup
- Cache behavior verified with multiple requests
- Eager loading tested to prevent N+1 queries
- Response format validation for API resources
- Default filter behavior across all endpoints

### Location Model Test Coverage

#### 6. LocationMigrationTest (`tests/Unit/Database/LocationMigrationTest.php`)
Database schema and PostGIS integration tests with 10 tests:

- ✅ Complete locations table schema validation
- ✅ PostGIS extension and geography column setup
- ✅ All expected indexes (GiST spatial, full-text search, composite)
- ✅ Foreign key constraints and cascading deletes
- ✅ Column data types and coordinate boundaries
- ✅ Default values and constraint validation
- ✅ PostGIS spatial functions and coordinate handling
- ✅ GiST and full-text search index configuration
- ✅ Coordinate boundary validation (-90 to +90 lat, -180 to +180 lon)
- ✅ PostGIS spatial function verification

#### 7. LocationTest (`tests/Unit/Models/LocationTest.php`)
Comprehensive Location model functionality with 25 tests:

**Basic Model Tests:**
- ✅ Fillable attributes and mass assignment protection
- ✅ Attribute casting (latitude/longitude as decimal, booleans)
- ✅ Model relationships (organization, wage reports)
- ✅ Factory functionality and model creation

**Spatial Scope Tests:**
- ✅ `scopeNear()` - finds locations within specified radius
- ✅ `scopeWithDistance()` - adds distance calculations to results 
- ✅ `scopeOrderByDistance()` - orders results by proximity
- ✅ Coordinate parameter validation and SQL injection prevention

**Search and Filter Scopes:**
- ✅ `scopeSearch()` - full-text search on name, address, city
- ✅ `scopeInCity()`, `scopeInState()`, `scopeInCountry()` - geographic filters
- ✅ `scopeActive()` and `scopeVerified()` - status filters
- ✅ `scopeDefaultFilters()` - applies standard filtering

**Model Features:**
- ✅ Route model binding (supports both ID and slug resolution)
- ✅ Computed attributes (`full_address`, `display_name`)
- ✅ PostGIS point updates via model events (created/updated)
- ✅ Spatial point synchronization with lat/lng changes

#### 8. LocationFactoryTest (`tests/Unit/Factories/LocationFactoryTest.php`)
Factory patterns and data generation with 18 tests:

**Factory State Management:**
- ✅ Basic factory creates valid locations with organization relationships
- ✅ `active()`, `inactive()`, `verified()` state methods
- ✅ `withCoordinates()` for precise coordinate specification
- ✅ Multiple factory states can be chained together

**City-Specific Generation:**
- ✅ `inCity()` method with 20 major US cities support
- ✅ Convenience methods: `newYork()`, `losAngeles()`, `chicago()`
- ✅ Unknown city defaults to New York coordinates
- ✅ Coordinate variation creates realistic geographic spread

**Data Quality and Performance:**
- ✅ Factory generates unique slugs automatically
- ✅ Uses realistic US cities array with accurate coordinates
- ✅ Location descriptors create varied naming patterns
- ✅ PostGIS point column set correctly via `configure()` callback
- ✅ Factory performance optimized for bulk creation
- ✅ Probability distributions match real-world patterns

#### 9. LocationSpatialTest (`tests/Feature/LocationSpatialTest.php`)  
PostGIS spatial query accuracy and performance with 9 tests:

**Distance Calculation Tests:**
- ✅ Distance calculations accurate with real US city coordinates
- ✅ Spatial search within radius finds expected locations
- ✅ Distance accuracy verified within ±500m tolerance
- ✅ Cross-country distance calculations (NYC to LA)

**Performance Requirements:**
- ✅ Spatial queries complete within 200ms requirement
- ✅ GiST index utilization for optimal PostGIS performance
- ✅ Complex spatial queries with multiple conditions
- ✅ Large result set handling and memory efficiency

**Edge Cases:**
- ✅ Empty result handling for searches with no matches
- ✅ Invalid coordinate handling and validation
- ✅ Coordinate boundary cases (poles, date line)

#### 10. LocationSeederTest (`tests/Feature/LocationSeederTest.php`)
Location seeding with US cities data and 15 tests:

**Seeder Functionality:**
- ✅ Seeder runs without errors and creates expected locations
- ✅ Creates organizations if none exist for location assignment
- ✅ Location data quality validation (addresses, coordinates, names)
- ✅ Accurate coordinates for 17 major US cities

**Geographic Distribution:**
- ✅ Locations created across expected cities (NYC, LA, Chicago, etc.)
- ✅ Geographic distribution spans continental United States
- ✅ Seeder distributes locations across multiple organizations
- ✅ Realistic address format and naming conventions

**Performance and Reliability:**
- ✅ PostGIS spatial points created and indexed correctly
- ✅ Seeder performance acceptable for development data
- ✅ Can be run multiple times without conflicts
- ✅ Console output provides helpful progress information
- ✅ Verification status assigned with realistic distribution

### Additional Test Classes

#### 11. OrganizationResourceTest (`tests/Unit/OrganizationResourceTest.php`)
Unit tests for API resource transformations with 8 tests:

- ✅ OrganizationListItemResource minimal field format
- ✅ Verification status boolean conversion
- ✅ Primary industry relationship inclusion/exclusion
- ✅ Null relationship handling
- ✅ OrganizationResource inheritance from list item resource
- ✅ DateTime formatting for ISO 8601 timestamps
- ✅ Optional field handling (verified_at)
- ✅ Resource collection behavior

## PHPUnit Configuration

Our `phpunit.xml` configuration:

```xml
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>app</directory>
        </include>
    </source>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="APP_MAINTENANCE_DRIVER" value="file"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="CACHE_STORE" value="array"/>
        <env name="DB_DATABASE" value="wdtpdb"/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="PULSE_ENABLED" value="false"/>
        <env name="TELESCOPE_ENABLED" value="false"/>
        <env name="NIGHTWATCH_ENABLED" value="false"/>
    </php>
</phpunit>
```

Key configurations:
- Uses `wdtpdb` database for testing
- Fast bcrypt rounds (4) for testing
- Array cache and session drivers for speed
- Disabled telemetry tools in testing

## Test Structure

### Current Directory Structure
```
tests/
├── Feature/
│   ├── ExampleTest.php          # Basic Laravel app test
│   └── HealthCheckTest.php      # API health check tests
├── Unit/
│   ├── ExampleTest.php          # Basic PHPUnit test
│   └── UserModelTest.php        # User model comprehensive tests
└── TestCase.php                 # Base test class
```

### Base TestCase
The project uses Laravel's base TestCase without custom modifications:
```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    //
}
```

## Testing Database Setup

### Database Configuration
Tests use the external PostgreSQL database with:
- Database: `wdtpdb` (separate from production)
- PostGIS extension enabled
- User model with WDTP-specific fields

### RefreshDatabase Usage
The `UserModelTest` uses `RefreshDatabase` trait to ensure clean database state:
```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;
    // Tests run with fresh database migration
}
```

## Factory Usage in Tests

### UserFactory States
The enhanced UserFactory provides these states for testing:

```php
// Role-based states (all set enabled=true)
User::factory()->admin()->create();
User::factory()->moderator()->create();
User::factory()->contributor()->create();
User::factory()->viewer()->create();

// Status states
User::factory()->enabled()->create();
User::factory()->disabled()->create();

// Basic factory with random data
User::factory()->create();
```

### Example Factory Usage in Tests
```php
public function test_user_factory_role_states(): void
{
    $adminUser = User::factory()->admin()->create();
    $this->assertEquals('admin', $adminUser->role);
    $this->assertTrue($adminUser->enabled);

    $viewerUser = User::factory()->viewer()->create();
    $this->assertEquals('viewer', $viewerUser->role);
}
```

## API Testing Examples

### Health Check API Tests
Current API tests demonstrate proper Laravel feature testing:

```php
public function test_basic_health_check(): void
{
    $response = $this->get('/api/v1/healthz');
    $response->assertStatus(200);
}

public function test_deep_health_check(): void
{
    $response = $this->get('/api/v1/healthz/deep');
    
    $response->assertStatus(200)
             ->assertJsonStructure([
                 'database',
                 'postgis'
             ]);
}
```

## Debugging Tests

### Test Debugging Tips

1. **Verbose Output**: Use `--verbose` flag for detailed test information
2. **Stop on Failure**: Use `--stop-on-failure` to stop at first failure
3. **Debug Specific Test**: Run individual tests with `--filter`
4. **Database State**: Use `dump()` or `dd()` to inspect data during tests

### Example Debug Output
The HealthCheckTest includes debug output for failures:
```php
if ($response->status() !== 200) {
    dump('Response status: ' . $response->status());
    dump('Response content: ' . $response->content());
}
```

## Next Steps for Testing

### Areas Needing Test Coverage
As the WDTP project grows, add tests for:

1. **Models**: ~~Industry~~, ~~Organization~~, ~~Location~~, WageReport (pending implementation)
2. **API Endpoints**: Location API endpoints, WageReport API endpoints
3. **~~Spatial Queries~~**: ~~PostGIS distance and location-based searches~~ ✅ COMPLETE
4. **Authentication**: Sanctum token authentication and role-based access
5. **Business Logic**: Wage calculations, moderation workflows
6. **Validation**: Input validation and error handling

### Completed Test Coverage
- **✅ User Model**: Complete with authentication and role testing
- **✅ Industry Model**: Hierarchical relationships, constraints, API resources
- **✅ Organization Model**: Business logic, API resources, caching
- **✅ Location Model**: Spatial queries, PostGIS integration, factory patterns
- **✅ Database Migrations**: PostGIS setup, indexes, constraints
- **✅ Factories and Seeders**: Test data generation with realistic US cities

### Current Test Organization (Implemented)
```
tests/
├── Feature/
│   ├── Auth/
│   │   ├── AuthenticationTest.php        ✅ COMPLETE
│   │   └── AdminSeederTest.php          ✅ COMPLETE
│   ├── API/
│   │   ├── IndustryApiTest.php          ✅ COMPLETE
│   │   ├── OrganizationApiTest.php      ✅ COMPLETE
│   │   ├── LocationApiTest.php          ❌ TODO
│   │   └── WageReportApiTest.php        ❌ TODO
│   ├── Spatial/
│   │   └── LocationSpatialTest.php      ✅ COMPLETE
│   ├── Seeders/
│   │   ├── IndustrySeederTest.php       ✅ COMPLETE
│   │   └── LocationSeederTest.php       ✅ COMPLETE
│   ├── ExampleTest.php                  ✅ COMPLETE
│   ├── HealthCheckTest.php              ✅ COMPLETE
│   └── SwaggerDocumentationTest.php     ✅ COMPLETE
├── Unit/
│   ├── Database/
│   │   ├── IndustryConstraintsTest.php  ✅ COMPLETE
│   │   └── LocationMigrationTest.php    ✅ COMPLETE
│   ├── Models/
│   │   ├── IndustryTest.php             ✅ COMPLETE
│   │   ├── LocationTest.php             ✅ COMPLETE
│   │   ├── UserModelTest.php            ✅ COMPLETE
│   │   └── WageReportTest.php           ❌ TODO
│   ├── Factories/
│   │   └── LocationFactoryTest.php      ✅ COMPLETE
│   ├── Resources/
│   │   ├── IndustryResourceTest.php     ✅ COMPLETE
│   │   ├── OrganizationResourceTest.php ✅ COMPLETE
│   │   └── LocationResourceTest.php     ❌ TODO
│   ├── Observers/
│   │   └── IndustryObserverTest.php     ✅ COMPLETE
│   └── ExampleTest.php                  ✅ COMPLETE
└── TestCase.php
```

### PostGIS Testing Patterns

#### Spatial Query Test Requirements
All Location spatial tests must verify:

```php
// Distance accuracy (±500m tolerance required)
$expectedDistance = 2902; // NYC to Brooklyn in meters
$actualDistance = $location->distance_meters;
$this->assertEqualsWithDelta($expectedDistance, $actualDistance, 500);

// Performance requirements (<200ms for spatial queries)
$startTime = microtime(true);
Location::near($lat, $lon, 10)->get();
$duration = (microtime(true) - $startTime) * 1000;
$this->assertLessThan(200, $duration);

// Realistic coordinate test data
const NYC_COORDS = [40.7128, -74.0060];
const LA_COORDS = [34.0522, -118.2437];
const CHICAGO_COORDS = [41.8781, -87.6298];
```

#### Factory Testing Standards
Location factory tests must verify:

```php
// Coordinate variation within realistic bounds (±0.01 degrees ≈ 1 mile)
$locations = Location::factory()->count(100)->newYork()->create();
$latRange = $locations->max('latitude') - $locations->min('latitude');
$this->assertLessThanOrEqual(0.02, $latRange);

// PostGIS point synchronization
$location = Location::factory()->create(['latitude' => 40.7128, 'longitude' => -74.0060]);
$point = DB::selectOne('SELECT ST_X(point::geometry) as lon, ST_Y(point::geometry) as lat FROM locations WHERE id = ?', [$location->id]);
$this->assertEquals(-74.0060, $point->lon, '', 0.0001);
```

This comprehensive testing framework provides excellent coverage of the WDTP Location model with PostGIS spatial functionality, realistic test data, and performance verification.