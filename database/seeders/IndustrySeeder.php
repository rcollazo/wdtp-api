<?php

namespace Database\Seeders;

use App\Models\Industry;
use Illuminate\Database\Seeder;

/**
 * Industry taxonomy seeder aligned to Google My Business categories.
 *
 * Reference: https://daltonluka.com/blog/google-my-business-categories
 *
 * This is a curated subset for WDTP launch. Future expansion will include
 * full GMB import with traceability via industry_aliases table.
 *
 * Future consideration: industry_aliases table structure:
 * - id, industry_id FK, source ENUM('gmb'), source_key TEXT UNIQUE, created_at
 */
class IndustrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedTaxonomy($this->getTaxonomy());
    }

    /**
     * Get the GMB-aligned taxonomy structure.
     */
    private function getTaxonomy(): array
    {
        return [
            // Root Categories (Level 0)
            'restaurants' => [
                'name' => 'Restaurants',
                'children' => [
                    'fast-food-restaurant' => 'Fast Food Restaurant',
                    'coffee-shop' => 'Coffee Shop',
                    'pizza-restaurant' => 'Pizza Restaurant',
                    'sandwich-shop' => 'Sandwich Shop',
                    'american-restaurant' => 'American Restaurant',
                    'mexican-restaurant' => 'Mexican Restaurant',
                ],
            ],
            'retail' => [
                'name' => 'Retail',
                'children' => [
                    'grocery-store' => 'Grocery Store',
                    'convenience-store' => 'Convenience Store',
                    'gas-station' => 'Gas Station',
                    'department-store' => 'Department Store',
                    'clothing-store' => 'Clothing Store',
                    'pharmacy' => 'Pharmacy',
                ],
            ],
            'health' => [
                'name' => 'Health',
                'children' => [
                    'medical-clinic' => 'Medical Clinic',
                    'dental-clinic' => 'Dental Clinic',
                    'urgent-care-center' => 'Urgent Care Center',
                    'physical-therapy-clinic' => 'Physical Therapy Clinic',
                ],
            ],
            'lodging' => [
                'name' => 'Lodging',
                'children' => [
                    'hotel' => 'Hotel',
                    'motel' => 'Motel',
                ],
            ],
            'automotive' => [
                'name' => 'Automotive',
                'children' => [
                    'auto-repair-shop' => 'Auto Repair Shop',
                    'car-wash' => 'Car Wash',
                    'gas-station' => 'Gas Station', // Note: duplicate from retail
                ],
            ],
            'professional-services' => [
                'name' => 'Professional Services',
                'children' => [
                    'accounting-service' => 'Accounting Service',
                    'legal-service' => 'Legal Service',
                    'real-estate-agency' => 'Real Estate Agency',
                ],
            ],
            'beauty-spas' => [
                'name' => 'Beauty & Spas',
                'children' => [
                    'hair-salon' => 'Hair Salon',
                    'nail-salon' => 'Nail Salon',
                    'spa' => 'Spa',
                ],
            ],
            'home-services' => [
                'name' => 'Home Services',
                'children' => [
                    'cleaning-service' => 'Cleaning Service',
                    'landscaping-service' => 'Landscaping Service',
                    'plumbing-service' => 'Plumbing Service',
                ],
            ],
        ];
    }

    /**
     * Seed the taxonomy with idempotent upserts and proper parent resolution.
     */
    private function seedTaxonomy(array $taxonomy, ?int $parentId = null, int $depth = 0): void
    {
        $sort = 10; // Start sort order at 10, increment by 10

        foreach ($taxonomy as $slug => $data) {
            // Handle array structure (root categories with children)
            if (is_array($data)) {
                $name = $data['name'];
                $children = $data['children'] ?? [];
            } else {
                // Handle string structure (child categories)
                $name = $data;
                $children = [];
            }

            // Upsert the industry record
            $industry = Industry::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'parent_id' => $parentId,
                    'sort' => $sort,
                    'is_active' => true,
                    'visible_in_ui' => true,
                ]
            );

            $this->command->info("Seeded industry: {$name} (slug: {$slug})");

            // Recursively seed children if they exist
            if (! empty($children)) {
                $childTaxonomy = [];
                foreach ($children as $childSlug => $childName) {
                    $childTaxonomy[$childSlug] = $childName;
                }
                $this->seedTaxonomy($childTaxonomy, $industry->id, $depth + 1);
            }

            $sort += 10; // Increment sort order
        }
    }
}
