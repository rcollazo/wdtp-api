# Product Context

2025-08-22 07:55:11 - Initialized from .projectBrief.md

## Project Goal
Create a GasBuddy-like platform for anonymous wage transparency at physical locations using Laravel + PostGIS stack.

## Key Features
- Spatial wage search with PostGIS geography(Point,4326)
- Laravel Sanctum authentication
- Gamification system with user levels
- Moderation workflow for wage reports
- Hierarchical industry categorization

## Overall Architecture
- Laravel 12 backend API
- External PostgreSQL/PostGIS database
- Three-tier separation:
  1. API Layer (/api/v1 routes)
  2. Service Layer (business logic)
  3. Data Layer (Eloquent + Magellan)