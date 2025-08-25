<?php

namespace Tests\Unit\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LocationMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that all expected columns exist with correct types.
     */
    public function test_locations_table_has_all_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('locations'));

        // Basic columns
        $this->assertTrue(Schema::hasColumn('locations', 'id'));
        $this->assertTrue(Schema::hasColumn('locations', 'organization_id'));
        $this->assertTrue(Schema::hasColumn('locations', 'name'));
        $this->assertTrue(Schema::hasColumn('locations', 'slug'));

        // Address columns
        $this->assertTrue(Schema::hasColumn('locations', 'address_line_1'));
        $this->assertTrue(Schema::hasColumn('locations', 'address_line_2'));
        $this->assertTrue(Schema::hasColumn('locations', 'city'));
        $this->assertTrue(Schema::hasColumn('locations', 'state_province'));
        $this->assertTrue(Schema::hasColumn('locations', 'postal_code'));
        $this->assertTrue(Schema::hasColumn('locations', 'country_code'));

        // Contact columns
        $this->assertTrue(Schema::hasColumn('locations', 'phone'));
        $this->assertTrue(Schema::hasColumn('locations', 'website_url'));
        $this->assertTrue(Schema::hasColumn('locations', 'description'));

        // Coordinate columns
        $this->assertTrue(Schema::hasColumn('locations', 'latitude'));
        $this->assertTrue(Schema::hasColumn('locations', 'longitude'));

        // Status columns
        $this->assertTrue(Schema::hasColumn('locations', 'is_active'));
        $this->assertTrue(Schema::hasColumn('locations', 'is_verified'));
        $this->assertTrue(Schema::hasColumn('locations', 'verification_notes'));

        // Timestamp columns
        $this->assertTrue(Schema::hasColumn('locations', 'created_at'));
        $this->assertTrue(Schema::hasColumn('locations', 'updated_at'));
    }

    /**
     * Test that PostGIS extension is enabled and geography column exists.
     */
    public function test_postgis_extension_and_geography_column_exist(): void
    {
        // Check PostGIS extension is available
        $extensions = DB::select("SELECT * FROM pg_extension WHERE extname = 'postgis'");
        $this->assertCount(1, $extensions, 'PostGIS extension should be installed');

        // Check geography column exists
        $columns = DB::select("
            SELECT column_name, data_type, udt_name 
            FROM information_schema.columns 
            WHERE table_name = 'locations' AND column_name = 'point'
        ");

        $this->assertCount(1, $columns, 'Geography point column should exist');
        $this->assertEquals('USER-DEFINED', $columns[0]->data_type);
        $this->assertEquals('geography', $columns[0]->udt_name);
    }

    /**
     * Test that all expected indexes exist.
     */
    public function test_all_expected_indexes_exist(): void
    {
        $indexes = DB::select("
            SELECT indexname, indexdef 
            FROM pg_indexes 
            WHERE tablename = 'locations'
            ORDER BY indexname
        ");

        $indexNames = collect($indexes)->pluck('indexname')->toArray();

        // Debug: show what indexes actually exist
        if (empty($indexNames)) {
            $this->fail('No indexes found - this should not happen');
        }

        // Check for expected indexes (be more flexible with naming)
        $hasPokerIndex = collect($indexNames)->contains(function ($name) {
            return str_contains($name, 'pkey');
        });
        $hasSlugIndex = collect($indexNames)->contains(function ($name) {
            return str_contains($name, 'slug');
        });
        $hasOrgIndex = collect($indexNames)->contains(function ($name) {
            return str_contains($name, 'organization_id');
        });
        $hasGistIndex = collect($indexNames)->contains(function ($name) {
            return str_contains($name, 'gist') || str_contains($name, 'point');
        });

        $this->assertTrue($hasPokerIndex, 'Primary key index should exist. Found: '.implode(', ', $indexNames));
        $this->assertTrue($hasSlugIndex, 'Slug index should exist. Found: '.implode(', ', $indexNames));
        $this->assertTrue($hasOrgIndex, 'Organization index should exist. Found: '.implode(', ', $indexNames));
        $this->assertTrue($hasGistIndex, 'GiST spatial index should exist. Found: '.implode(', ', $indexNames));
    }

    /**
     * Test that the GiST index is properly configured for spatial queries.
     */
    public function test_gist_index_is_properly_configured(): void
    {
        $gistIndex = DB::select("
            SELECT indexname, indexdef 
            FROM pg_indexes 
            WHERE tablename = 'locations' AND indexname = 'locations_point_gist_idx'
        ");

        $this->assertCount(1, $gistIndex, 'GiST index should exist');
        $this->assertStringContainsString('USING gist', $gistIndex[0]->indexdef, 'Should use GiST method');
        $this->assertStringContainsString('point', $gistIndex[0]->indexdef, 'Should index the point column');
    }

    /**
     * Test that full-text search index is properly configured.
     */
    public function test_fulltext_search_index_is_properly_configured(): void
    {
        $fulltextIndex = DB::select("
            SELECT indexname, indexdef 
            FROM pg_indexes 
            WHERE tablename = 'locations' AND indexname = 'locations_name_address_city_fulltext'
        ");

        $this->assertCount(1, $fulltextIndex, 'Full-text search index should exist');
        $this->assertStringContainsString('USING gin', $fulltextIndex[0]->indexdef, 'Should use GIN method');
        $this->assertStringContainsString('to_tsvector', $fulltextIndex[0]->indexdef, 'Should use text search vectors');
    }

    /**
     * Test foreign key constraint exists and is properly configured.
     */
    public function test_foreign_key_constraint_exists(): void
    {
        $foreignKeys = DB::select("
            SELECT
                conname as constraint_name,
                conrelid::regclass as table_name,
                confrelid::regclass as referenced_table
            FROM pg_constraint
            WHERE contype = 'f' 
            AND conrelid = 'locations'::regclass
        ");

        $this->assertCount(1, $foreignKeys, 'Should have one foreign key constraint');
        $this->assertEquals('locations_organization_id_foreign', $foreignKeys[0]->constraint_name);
        $this->assertEquals('locations', $foreignKeys[0]->table_name);
        $this->assertEquals('organizations', $foreignKeys[0]->referenced_table);
    }

    /**
     * Test column data types and constraints.
     */
    public function test_column_data_types_and_constraints(): void
    {
        $columns = DB::select("
            SELECT 
                column_name,
                data_type,
                is_nullable,
                column_default,
                character_maximum_length,
                numeric_precision,
                numeric_scale
            FROM information_schema.columns 
            WHERE table_name = 'locations'
            ORDER BY ordinal_position
        ");

        $columnMap = collect($columns)->keyBy('column_name');

        // Test key columns
        $this->assertEquals('bigint', $columnMap['id']->data_type);
        $this->assertEquals('NO', $columnMap['id']->is_nullable);

        $this->assertEquals('bigint', $columnMap['organization_id']->data_type);
        $this->assertEquals('NO', $columnMap['organization_id']->is_nullable);

        $this->assertEquals('character varying', $columnMap['name']->data_type);
        $this->assertEquals(255, $columnMap['name']->character_maximum_length);
        $this->assertEquals('NO', $columnMap['name']->is_nullable);

        $this->assertEquals('character varying', $columnMap['slug']->data_type);
        $this->assertEquals(255, $columnMap['slug']->character_maximum_length);
        $this->assertEquals('NO', $columnMap['slug']->is_nullable);

        // Test address columns
        $this->assertEquals('character varying', $columnMap['city']->data_type);
        $this->assertEquals(100, $columnMap['city']->character_maximum_length);
        $this->assertEquals('NO', $columnMap['city']->is_nullable);

        $this->assertEquals('character varying', $columnMap['state_province']->data_type);
        $this->assertEquals(100, $columnMap['state_province']->character_maximum_length);
        $this->assertEquals('NO', $columnMap['state_province']->is_nullable);

        $this->assertEquals('character varying', $columnMap['country_code']->data_type);
        $this->assertEquals(2, $columnMap['country_code']->character_maximum_length);
        $this->assertEquals('NO', $columnMap['country_code']->is_nullable);

        // Test coordinate columns
        $this->assertEquals('numeric', $columnMap['latitude']->data_type);
        $this->assertEquals(10, $columnMap['latitude']->numeric_precision);
        $this->assertEquals(8, $columnMap['latitude']->numeric_scale);
        $this->assertEquals('NO', $columnMap['latitude']->is_nullable);

        $this->assertEquals('numeric', $columnMap['longitude']->data_type);
        $this->assertEquals(11, $columnMap['longitude']->numeric_precision);
        $this->assertEquals(8, $columnMap['longitude']->numeric_scale);
        $this->assertEquals('NO', $columnMap['longitude']->is_nullable);

        // Test boolean columns
        $this->assertEquals('boolean', $columnMap['is_active']->data_type);
        $this->assertEquals('boolean', $columnMap['is_verified']->data_type);

        // Test nullable columns
        $this->assertEquals('YES', $columnMap['address_line_2']->is_nullable);
        $this->assertEquals('YES', $columnMap['phone']->is_nullable);
        $this->assertEquals('YES', $columnMap['website_url']->is_nullable);
        $this->assertEquals('YES', $columnMap['description']->is_nullable);
        $this->assertEquals('YES', $columnMap['verification_notes']->is_nullable);
    }

    /**
     * Test default values are correctly set.
     */
    public function test_default_values_are_correctly_set(): void
    {
        $columns = DB::select("
            SELECT 
                column_name,
                column_default
            FROM information_schema.columns 
            WHERE table_name = 'locations' 
            AND column_default IS NOT NULL
        ");

        $defaultMap = collect($columns)->keyBy('column_name')->map->column_default;

        // Test default values
        $this->assertEquals("'US'::character varying", $defaultMap['country_code']);
        $this->assertEquals('true', $defaultMap['is_active']);
        $this->assertEquals('false', $defaultMap['is_verified']);
    }

    /**
     * Test that the migration can handle coordinate validation boundaries.
     */
    public function test_coordinate_boundaries_are_properly_handled(): void
    {
        // Create industry and organization first
        DB::table('industries')->insert([
            'name' => 'Test Industry',
            'slug' => 'test-industry',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organizations')->insert([
            'name' => 'Test Org',
            'slug' => 'test-org',
            'primary_industry_id' => null, // Allow null industry
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Test valid coordinate ranges
        $validCoordinates = [
            ['lat' => 90.0, 'lon' => 180.0],      // Maximum valid
            ['lat' => -90.0, 'lon' => -180.0],    // Minimum valid
            ['lat' => 0.0, 'lon' => 0.0],         // Origin
            ['lat' => 40.7128, 'lon' => -74.0060], // NYC (realistic)
        ];

        foreach ($validCoordinates as $index => $coords) {
            DB::table('locations')->insert([
                'organization_id' => 1,
                'name' => "Test Location {$index}",
                'slug' => "test-location-{$index}",
                'address_line_1' => '123 Test St',
                'city' => 'Test City',
                'state_province' => 'TS',
                'postal_code' => '12345',
                'latitude' => $coords['lat'],
                'longitude' => $coords['lon'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $insertedCount = DB::table('locations')->count();
        $this->assertEquals(4, $insertedCount, 'All valid coordinates should be insertable');
    }

    /**
     * Test that PostGIS spatial functions work with the geography column.
     */
    public function test_postgis_spatial_functions_work(): void
    {
        // This is a basic test to ensure PostGIS functions are available
        $result = DB::select('SELECT ST_Point(-74.0060, 40.7128) as point');
        $this->assertNotNull($result[0]->point, 'ST_Point function should work');

        $result = DB::select('SELECT ST_SetSRID(ST_MakePoint(-74.0060, 40.7128), 4326) as point');
        $this->assertNotNull($result[0]->point, 'ST_SetSRID and ST_MakePoint functions should work');

        $result = DB::select('SELECT ST_Distance(
            ST_SetSRID(ST_MakePoint(-74.0060, 40.7128), 4326)::geography,
            ST_SetSRID(ST_MakePoint(-73.9855, 40.7580), 4326)::geography
        ) as distance');
        $this->assertIsNumeric($result[0]->distance, 'ST_Distance with geography should return numeric distance');
        $this->assertGreaterThan(0, $result[0]->distance, 'Distance should be positive');
    }
}
