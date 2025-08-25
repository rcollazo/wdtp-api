<?php

namespace Database\Factories;

use App\Models\Industry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PositionCategory>
 */
class PositionCategoryFactory extends Factory
{
    /**
     * Position categories by industry type.
     */
    private array $positionsByIndustry = [
        'food-service' => [
            'Server', 'Bartender', 'Host/Hostess', 'Cook', 'Kitchen Assistant',
            'Dishwasher', 'Manager', 'Assistant Manager', 'Cashier', 'Food Runner',
        ],
        'retail' => [
            'Sales Associate', 'Cashier', 'Store Manager', 'Assistant Manager',
            'Stock Associate', 'Customer Service Representative', 'Visual Merchandiser',
            'Loss Prevention Officer', 'Department Supervisor', 'Sales Lead',
        ],
        'healthcare' => [
            'Certified Nursing Assistant', 'Medical Assistant', 'Receptionist',
            'Medical Scribe', 'Pharmacy Technician', 'Radiology Technician',
            'Physical Therapy Assistant', 'Medical Billing Specialist', 'Unit Secretary',
        ],
        'hospitality' => [
            'Front Desk Agent', 'Housekeeper', 'Bellhop', 'Concierge',
            'Maintenance Worker', 'Valet', 'Room Service', 'Banquet Server',
            'Hotel Manager', 'Night Auditor',
        ],
        'manufacturing' => [
            'Production Worker', 'Assembly Line Worker', 'Quality Control Inspector',
            'Machine Operator', 'Warehouse Associate', 'Forklift Operator',
            'Maintenance Technician', 'Supervisor', 'Shipping Clerk', 'Packer',
        ],
        'default' => [
            'Associate', 'Team Member', 'Specialist', 'Representative', 'Assistant',
            'Coordinator', 'Supervisor', 'Manager', 'Lead', 'Technician',
        ],
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $positions = $this->positionsByIndustry['default'];
        $name = fake()->randomElement($positions).' '.fake()->word();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(10),
            'industry_id' => Industry::factory(),
            'status' => fake()->randomElement(['active', 'inactive']),
        ];
    }

    /**
     * Create position categories for food service industry.
     */
    public function foodService(): static
    {
        return $this->state(function (array $attributes) {
            $positions = $this->positionsByIndustry['food-service'];
            $name = fake()->randomElement($positions);

            return [
                'name' => $name,
                'slug' => Str::slug($name),
                'description' => $this->getPositionDescription($name),
            ];
        });
    }

    /**
     * Create position categories for retail industry.
     */
    public function retail(): static
    {
        return $this->state(function (array $attributes) {
            $positions = $this->positionsByIndustry['retail'];
            $name = fake()->randomElement($positions);

            return [
                'name' => $name,
                'slug' => Str::slug($name),
                'description' => $this->getPositionDescription($name),
            ];
        });
    }

    /**
     * Create position categories for healthcare industry.
     */
    public function healthcare(): static
    {
        return $this->state(function (array $attributes) {
            $positions = $this->positionsByIndustry['healthcare'];
            $name = fake()->randomElement($positions);

            return [
                'name' => $name,
                'slug' => Str::slug($name),
                'description' => $this->getPositionDescription($name),
            ];
        });
    }

    /**
     * Create an active position category.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    /**
     * Create an inactive position category.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Get a realistic description for a position.
     */
    private function getPositionDescription(string $position): string
    {
        $descriptions = [
            'Server' => 'Takes customer orders, serves food and beverages, processes payments',
            'Bartender' => 'Prepares and serves alcoholic and non-alcoholic beverages',
            'Cashier' => 'Processes customer transactions and handles money exchanges',
            'Sales Associate' => 'Assists customers with product selection and completes sales',
            'Cook' => 'Prepares food items according to recipes and safety standards',
            'Manager' => 'Supervises daily operations and manages staff',
        ];

        return $descriptions[$position] ?? fake()->sentence(8);
    }
}
