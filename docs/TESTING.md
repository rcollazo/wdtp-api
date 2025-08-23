# WDTP Testing Guide

## Overview
This guide covers testing practices, patterns, and conventions for the **What Do They Pay (WDTP)** platform. We use PHPUnit for all testing and follow Laravel's testing conventions.

## Running Existing Tests

### Current Test Suite Status
The WDTP project currently has **6 test classes** with **33 passing tests** and **142 assertions**:

**Unit Tests:**
- `ExampleTest` - Basic PHPUnit example test (1 test)
- `UserModelTest` - Comprehensive User model testing (12 tests)

**Feature Tests:**
- `ExampleTest` - Basic Laravel application test (1 test)
- `HealthCheckTest` - API health check endpoints (2 tests)
- `AuthenticationTest` - Complete authentication flow testing (13 tests)
- `AdminSeederTest` - Admin user seeding functionality (4 tests)

### Running Tests

```bash
# Run all tests (33 tests currently)
./vendor/bin/sail test

# Run specific test suite
./vendor/bin/sail test --testsuite=Unit      # 13 tests
./vendor/bin/sail test --testsuite=Feature   # 20 tests

# Run specific test class
./vendor/bin/sail test --filter=UserModelTest
./vendor/bin/sail test tests/Unit/UserModelTest.php
./vendor/bin/sail test tests/Feature/HealthCheckTest.php
./vendor/bin/sail test tests/Feature/AuthenticationTest.php
./vendor/bin/sail test tests/Feature/AdminSeederTest.php

# Run specific test method
./vendor/bin/sail test --filter=test_user_fillable_attributes

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

Tests:    33 passed (142 assertions)
Duration: ~2.1s
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

1. **Models**: Industry, Organization, Location, WageReport
2. **API Endpoints**: All RESTful API endpoints with authentication
3. **Spatial Queries**: PostGIS distance and location-based searches
4. **Authentication**: Sanctum token authentication and role-based access
5. **Business Logic**: Wage calculations, moderation workflows
6. **Validation**: Input validation and error handling

### Suggested Test Organization
```
tests/
├── Feature/
│   ├── Auth/
│   │   ├── LoginTest.php
│   │   ├── RegistrationTest.php
│   │   └── TokenAuthTest.php
│   ├── API/
│   │   ├── IndustryApiTest.php
│   │   ├── OrganizationApiTest.php
│   │   ├── LocationApiTest.php
│   │   └── WageReportApiTest.php
│   └── Spatial/
│       ├── LocationSearchTest.php
│       └── DistanceCalculationTest.php
├── Unit/
│   ├── Models/
│   │   ├── IndustryTest.php
│   │   ├── OrganizationTest.php
│   │   ├── LocationTest.php
│   │   └── WageReportTest.php
│   └── Services/
│       ├── WageCalculatorTest.php
│       └── LocationServiceTest.php
└── TestCase.php
```

This foundation provides a solid testing framework for the WDTP project with comprehensive coverage of the User model and basic application functionality.