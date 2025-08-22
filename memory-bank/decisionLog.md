# Decision Log

2025-08-22 07:57:15 - Initial architectural decisions

## Database Technology
* **Decision**: Use PostgreSQL with PostGIS extension
* **Rationale**: Required for geographic queries and GiST indexes
* **Implementation**: External DB server with Magellan ORM integration

## Authentication Strategy  
* **Decision**: Laravel Sanctum token-based auth
* **Rationale**: Lightweight stateless authentication for API clients
* **Implementation**: Sanctum middleware on API routes

## Architecture Layers
* **Decision**: Three-tier separation (API/Service/Data)
* **Rationale**: Clear separation of concerns and testability
* **Implementation**: Route > Controller > Service > Eloquent model