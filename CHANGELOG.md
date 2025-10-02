# Changelog

All notable changes to the WDTP API project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [v0.1.0] - 2025-10-02

### Added

**Core Infrastructure**
- Laravel 12 application with PHP 8.3 and PostgreSQL 17 + PostGIS 3.5
- Laravel Sail container environment for development
- External database configuration support
- Health check endpoints for monitoring

**Authentication & User Management**
- Laravel Sanctum token-based API authentication
- User model with role-based access control (admin, moderator, contributor, viewer)
- User registration, login, and profile endpoints
- Comprehensive authentication tests (13 tests)

**Industries System**
- Hierarchical industry categorization with parent/child relationships
- Industry model with slug-based routing
- Full API endpoints: index, show, autocomplete
- Comprehensive test coverage (49 tests across 4 test classes)

**Organizations System**
- Organization model with industry relationships
- Active/verified status workflow
- Full-text search on name and description
- API endpoints with pagination and caching
- Comprehensive test coverage (31 tests across 2 test classes)

**Locations System**
- Location model with PostGIS spatial capabilities
- Dual coordinate storage (geography column + cached lat/lng)
- Spatial scopes: `near()`, `withDistance()`, `orderByDistance()`
- Full-text search on name, address, and city
- GiST spatial indexes for <200ms query performance
- Route model binding (ID and slug resolution)
- API endpoints with spatial search
- **OpenStreetMap Integration**: Optional OSM fields (osm_id, osm_type, osm_data)
  - Factory states for OSM data generation
  - 15% of seeded locations include OSM linkage
  - Backward compatible - all OSM fields optional
- Comprehensive test coverage (72+ tests across 5 test suites)

**Location Search (Unified)**
- Text-based search with PostgreSQL full-text ranking
- Spatial filtering with configurable radius
- Relevance scoring combining text match (60%) and proximity (40%)
- Unified search merging WDTP locations and OpenStreetMap POIs
- RelevanceScorer service for result ranking
- OverpassService for OSM integration
- OsmLocation DTO for structured OSM data
- UnifiedLocationResource for consistent API responses
- Comprehensive validation and error handling
- Integration tests with real Overpass API (30 tests)

**Position Categories System**
- Position category model with industry relationships
- Active/inactive status workflow
- Unique position names per industry constraint
- Auto-generated slugs with route model binding
- Full-text search on name and description
- API endpoints with caching (5-minute TTL)
- Comprehensive test coverage (119 tests across 7 test classes)

**Wage Reports System**
- WageReport model with status workflow (pending → approved/rejected/flagged)
- Anonymous and authenticated wage submissions
- Rate limiting (10 reports per hour per IP/user)
- Spatial wage report search with PostGIS
- Wage statistics API with PostgreSQL percentiles
- WageReport observer with automatic counter management
- Moderation workflow support (ready for admin/moderator roles)
- Comprehensive test coverage (149+ tests across 7 test classes)

**Database Architecture**
- PostGIS 3.5 spatial extension
- GiST indexes for spatial queries
- Full-text search indexes (GIN)
- Comprehensive migrations for all models
- Factory patterns for all models
- Database seeders with realistic US city data

**API Documentation**
- Swagger/OpenAPI specification
- Comprehensive endpoint documentation
- Request/response examples
- Schema definitions for all models
- Swagger UI integration (6 tests)

**Testing Infrastructure**
- PHPUnit test framework
- 449+ passing tests with 98.5% pass rate
- 643+ test methods across 42 test classes
- Feature tests for all API endpoints
- Unit tests for all models and services
- Integration tests for spatial queries
- Performance benchmarks validated

**Project Documentation**
- CLAUDE.md with complete project context
- Development workflow documentation
- Git worktree and branch structure guidelines
- Testing patterns and conventions
- Database design patterns
- API endpoint structure

### Technical Specifications

- **Framework**: Laravel 12.0
- **PHP**: 8.3
- **Database**: PostgreSQL 17 + PostGIS 3.5
- **Authentication**: Laravel Sanctum
- **Testing**: PHPUnit
- **Container**: Laravel Sail
- **API Version**: v1
- **Spatial Query Performance**: <200ms (tested)
- **API Response Time**: <500ms (tested)
- **Test Pass Rate**: 98.5% (449+ of 456+ tests)

### Architecture Highlights

- RESTful API design with /api/v1 prefix
- Token-based authentication (non-expiring Sanctum tokens)
- Role-based authorization (4 roles: admin, moderator, contributor, viewer)
- Dual coordinate storage for optimal spatial performance
- Full-text search with PostgreSQL tsvector/tsquery
- JSONB storage for flexible OSM data
- Resource-based API responses
- Comprehensive validation with FormRequest classes
- Service layer for complex business logic
- Observer pattern for model lifecycle hooks
- Factory states for flexible test data generation

[v0.1.0]: https://github.com/rcollazo/wdtp-api/releases/tag/v0.1.0
