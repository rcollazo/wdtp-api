# Known Testing Issues

## Missing `industry_id` Column in Organizations Table

Running `./vendor/bin/sail artisan test --filter=UnifiedLocationSearchTest` currently fails because the database schema used for tests does not include the legacy `industry_id` column on the `organizations` table. Several factories still attempt to populate this column, so every test case that creates an organization throws a PostgreSQL `SQLSTATE[42703]: Undefined column` error before our assertions run.

### Suggested Fix

Either update the schema (add the column or adjust migrations) or refactor the factories/seeders to stop writing `industry_id`. Until then, the unified location search feature specs cannot execute successfully.
