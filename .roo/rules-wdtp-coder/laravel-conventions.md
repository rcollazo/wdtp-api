# Laravel 12 Conventions for WDTP

## Sail Commands
- Always use `./vendor/bin/sail` prefix for all commands
- Never install local PostgreSQL services (use external DB)

## Testing Standards
- Write PHPUnit tests for each feature as implemented
- Use `./vendor/bin/sail test --filter=TestName` for focused testing
- Create factories for realistic test data

## Spatial Query Patterns
- Use `ST_DWithin(point, ST_SetSRID(ST_MakePoint(:lon,:lat),4326)::geography, :meters)`
- Include `distance_meters` in API responses when `near` parameter provided
- Always use `geography(Point,4326)` type with GiST indexes

## API Response Format
- Use Laravel API Resources for clean JSON responses
- Hide internal fields (review_notes, etc.) from public endpoints
- Include proper pagination and filtering
