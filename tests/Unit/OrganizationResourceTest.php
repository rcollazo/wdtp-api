<?php

namespace Tests\Unit;

use App\Http\Resources\OrganizationListItemResource;
use App\Http\Resources\OrganizationResource;
use App\Models\Industry;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrganizationResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test OrganizationListItemResource with basic fields.
     */
    public function test_organization_list_item_resource_returns_minimal_fields(): void
    {
        $organization = Organization::factory()->create([
            'primary_industry_id' => null,
            'locations_count' => 5,
            'wage_reports_count' => 12,
            'verification_status' => 'verified',
        ]);

        $resource = new OrganizationListItemResource($organization);
        $result = $resource->toArray(new Request);

        $expected = [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'domain' => $organization->domain,
            'locations_count' => 5,
            'wage_reports_count' => 12,
            'is_verified' => true,
        ];

        $this->assertEquals($expected, $result);
    }

    /**
     * Test OrganizationListItemResource with unverified status.
     */
    public function test_organization_list_item_resource_handles_unverified_status(): void
    {
        $organization = Organization::factory()->create([
            'verification_status' => 'pending',
        ]);

        $resource = new OrganizationListItemResource($organization);
        $result = $resource->toArray(new Request);

        $this->assertFalse($result['is_verified']);
    }

    /**
     * Test OrganizationListItemResource with loaded industry relationship.
     */
    public function test_organization_list_item_resource_includes_loaded_industry(): void
    {
        $industry = Industry::factory()->create([
            'name' => 'Technology',
            'slug' => 'technology',
        ]);

        $organization = Organization::factory()->create([
            'primary_industry_id' => $industry->id,
        ]);

        // Load the relationship explicitly
        $organization->load('primaryIndustry');

        $resource = new OrganizationListItemResource($organization);
        $result = $resource->toArray(new Request);

        $expected = [
            'id' => $industry->id,
            'name' => 'Technology',
            'slug' => 'technology',
        ];

        $this->assertEquals($expected, $result['primary_industry']);
    }

    /**
     * Test OrganizationListItemResource without loaded industry relationship.
     */
    public function test_organization_list_item_resource_excludes_unloaded_industry(): void
    {
        $industry = Industry::factory()->create();
        $organization = Organization::factory()->create([
            'primary_industry_id' => $industry->id,
        ]);

        // Don't load the relationship
        $resource = new OrganizationListItemResource($organization);
        $result = $resource->toArray(new Request);

        $this->assertArrayNotHasKey('primary_industry', $result);
    }

    /**
     * Test OrganizationListItemResource handles null industry gracefully.
     */
    public function test_organization_list_item_resource_handles_null_industry(): void
    {
        $organization = Organization::factory()->create([
            'primary_industry_id' => null,
        ]);

        // Load the relationship which will be null
        $organization->load('primaryIndustry');

        $resource = new OrganizationListItemResource($organization);
        $result = $resource->toArray(new Request);

        $this->assertNull($result['primary_industry']);
    }

    /**
     * Test OrganizationResource extends OrganizationListItemResource.
     */
    public function test_organization_resource_extends_list_item_resource(): void
    {
        $industry = Industry::factory()->create([
            'name' => 'Healthcare',
            'slug' => 'healthcare',
        ]);

        $organization = Organization::factory()->create([
            'name' => 'Test Hospital',
            'slug' => 'test-hospital',
            'domain' => 'hospital.com',
            'legal_name' => 'Test Hospital Inc.',
            'website_url' => 'https://hospital.com',
            'description' => 'A test hospital',
            'logo_url' => 'https://hospital.com/logo.png',
            'primary_industry_id' => $industry->id,
            'verification_status' => 'verified',
            'verified_at' => now(),
            'locations_count' => 3,
            'wage_reports_count' => 8,
        ]);

        $organization->load('primaryIndustry');

        $resource = new OrganizationResource($organization);
        $result = $resource->toArray(new Request);

        // Should include all fields from OrganizationListItemResource
        $this->assertEquals($organization->id, $result['id']);
        $this->assertEquals('Test Hospital', $result['name']);
        $this->assertEquals('test-hospital', $result['slug']);
        $this->assertEquals('hospital.com', $result['domain']);
        $this->assertEquals(3, $result['locations_count']);
        $this->assertEquals(8, $result['wage_reports_count']);
        $this->assertTrue($result['is_verified']);

        // Should include additional fields
        $this->assertEquals('Test Hospital Inc.', $result['legal_name']);
        $this->assertEquals('https://hospital.com', $result['website_url']);
        $this->assertEquals('A test hospital', $result['description']);
        $this->assertEquals('https://hospital.com/logo.png', $result['logo_url']);

        // Should include formatted timestamps
        $this->assertStringContainsString('T', $result['verified_at']);
        $this->assertStringContainsString('T', $result['created_at']);
        $this->assertStringContainsString('T', $result['updated_at']);

        // Should include industry data
        $expected_industry = [
            'id' => $industry->id,
            'name' => 'Healthcare',
            'slug' => 'healthcare',
        ];
        $this->assertEquals($expected_industry, $result['primary_industry']);
    }

    /**
     * Test OrganizationResource with null verified_at.
     */
    public function test_organization_resource_handles_null_verified_at(): void
    {
        $organization = Organization::factory()->create([
            'verified_at' => null,
        ]);

        $resource = new OrganizationResource($organization);
        $result = $resource->toArray(new Request);

        $this->assertNull($result['verified_at']);
    }

    /**
     * Test OrganizationResource datetime formatting.
     */
    public function test_organization_resource_formats_datetime_as_iso_string(): void
    {
        $verifiedAt = now()->setMicroseconds(0);
        $organization = Organization::factory()->create([
            'verified_at' => $verifiedAt,
        ]);

        $resource = new OrganizationResource($organization);
        $result = $resource->toArray(new Request);

        $this->assertEquals($verifiedAt->toISOString(), $result['verified_at']);
        $this->assertEquals($organization->created_at->toISOString(), $result['created_at']);
        $this->assertEquals($organization->updated_at->toISOString(), $result['updated_at']);
    }
}
