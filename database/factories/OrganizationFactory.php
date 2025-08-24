<?php

namespace Database\Factories;

use App\Models\Industry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $companyNames = [
            'Coffee Bean Company',
            'Fresh Market Foods',
            'Tech Solutions Inc',
            'Global Retail Corp',
            'Local Restaurant Group',
            'Manufacturing Works',
            'Service Industries Ltd',
            'Digital Dynamics',
            'Urban Eatery',
            'Retail Innovations',
        ];

        $baseName = fake()->randomElement($companyNames);
        $name = $baseName.' '.fake()->numberBetween(100, 999); // Add unique suffix
        $slug = Str::slug($name);
        $domain = Str::slug($baseName, '').fake()->numberBetween(100, 999).'.com';

        return [
            'name' => $name,
            'slug' => $slug,
            'legal_name' => $name.' '.fake()->randomElement(['Corporation', 'LLC', 'Inc', 'Ltd']),
            'website_url' => fake()->boolean(70) ? 'https://'.$domain : null,
            'domain' => $domain,
            'description' => fake()->optional(0.8)->sentence(),
            'logo_url' => null, // Can be set manually in tests if needed
            'primary_industry_id' => Industry::factory(),
            'status' => fake()->randomElement(['active', 'inactive', 'suspended']),
            'verification_status' => fake()->randomElement(['verified', 'pending', 'rejected']),
            'created_by' => null, // Can be set manually in tests
            'verified_by' => null, // Can be set manually in tests
            'verified_at' => fake()->optional(0.3)->dateTimeBetween('-1 year'),
            'locations_count' => fake()->numberBetween(0, 100),
            'wage_reports_count' => fake()->numberBetween(0, 50),
            'is_active' => true,
            'visible_in_ui' => true,
        ];
    }

    /**
     * Create a verified organization state.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_status' => 'verified',
            'verified_at' => fake()->dateTimeBetween('-1 year'),
        ]);
    }

    /**
     * Create a suspended organization state.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
            'verification_status' => 'pending',
            'verified_at' => null,
        ]);
    }

    /**
     * Create an active organization state.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'is_active' => true,
            'visible_in_ui' => true,
        ]);
    }

    /**
     * Create an inactive organization state.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
            'is_active' => false,
        ]);
    }

    /**
     * Create a hidden organization state.
     */
    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => [
            'visible_in_ui' => false,
        ]);
    }

    /**
     * Create an organization with locations.
     */
    public function withLocations(?int $count = null): static
    {
        $locationCount = $count ?? fake()->numberBetween(1, 10);

        return $this->state(fn (array $attributes) => [
            'locations_count' => $locationCount,
        ]);
    }

    /**
     * Create an organization without locations.
     */
    public function withoutLocations(): static
    {
        return $this->state(fn (array $attributes) => [
            'locations_count' => 0,
        ]);
    }

    /**
     * Create an organization with wage reports.
     */
    public function withWageReports(?int $count = null): static
    {
        $reportCount = $count ?? fake()->numberBetween(1, 20);

        return $this->state(fn (array $attributes) => [
            'wage_reports_count' => $reportCount,
        ]);
    }
}
