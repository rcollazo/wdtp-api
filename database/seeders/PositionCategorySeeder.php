<?php

namespace Database\Seeders;

use App\Models\Industry;
use App\Models\PositionCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PositionCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding position categories...');

        // Get all industries
        $industries = Industry::all()->keyBy('slug');

        // Position categories by industry
        $positionCategories = [
            'restaurants' => [
                ['name' => 'Server', 'description' => 'Takes customer orders, serves food and beverages, processes payments'],
                ['name' => 'Bartender', 'description' => 'Prepares and serves alcoholic and non-alcoholic beverages to customers'],
                ['name' => 'Host/Hostess', 'description' => 'Greets customers, manages seating arrangements, and takes reservations'],
                ['name' => 'Cook', 'description' => 'Prepares food items according to recipes and safety standards'],
                ['name' => 'Kitchen Assistant', 'description' => 'Assists cooks with food preparation and kitchen maintenance'],
                ['name' => 'Dishwasher', 'description' => 'Cleans dishes, utensils, and kitchen equipment'],
                ['name' => 'Manager', 'description' => 'Supervises restaurant operations and manages staff'],
                ['name' => 'Assistant Manager', 'description' => 'Supports manager with daily operations and staff supervision'],
                ['name' => 'Cashier', 'description' => 'Processes customer payments and handles cash transactions'],
                ['name' => 'Food Runner', 'description' => 'Delivers food from kitchen to customer tables'],
            ],
            'fast-food-restaurant' => [
                ['name' => 'Crew Member', 'description' => 'Multi-task role including food preparation, order taking, and cleaning'],
                ['name' => 'Cashier', 'description' => 'Takes customer orders and processes payments at register'],
                ['name' => 'Cook', 'description' => 'Prepares fast food items following standardized procedures'],
                ['name' => 'Shift Leader', 'description' => 'Leads crew during shifts and ensures smooth operations'],
                ['name' => 'Assistant Manager', 'description' => 'Supports general manager with daily operations'],
                ['name' => 'General Manager', 'description' => 'Oversees all restaurant operations and manages staff'],
            ],
            'retail' => [
                ['name' => 'Sales Associate', 'description' => 'Assists customers with product selection and completes sales transactions'],
                ['name' => 'Cashier', 'description' => 'Processes customer transactions and handles money exchanges'],
                ['name' => 'Store Manager', 'description' => 'Manages store operations, inventory, and staff'],
                ['name' => 'Assistant Manager', 'description' => 'Supports store manager with daily operations'],
                ['name' => 'Stock Associate', 'description' => 'Receives inventory, stocks shelves, and maintains store appearance'],
                ['name' => 'Customer Service Representative', 'description' => 'Handles customer inquiries, returns, and complaints'],
                ['name' => 'Visual Merchandiser', 'description' => 'Creates attractive product displays and store layouts'],
                ['name' => 'Loss Prevention Officer', 'description' => 'Monitors store for theft and maintains security'],
                ['name' => 'Department Supervisor', 'description' => 'Oversees specific department operations and staff'],
                ['name' => 'Sales Lead', 'description' => 'Leads sales team and assists with complex transactions'],
            ],
            'health' => [
                ['name' => 'Certified Nursing Assistant', 'description' => 'Provides basic patient care and assists nursing staff'],
                ['name' => 'Medical Assistant', 'description' => 'Performs clinical and administrative tasks in medical facilities'],
                ['name' => 'Receptionist', 'description' => 'Greets patients, schedules appointments, and manages front desk'],
                ['name' => 'Medical Scribe', 'description' => 'Documents patient encounters and maintains medical records'],
                ['name' => 'Pharmacy Technician', 'description' => 'Assists pharmacists with medication preparation and customer service'],
                ['name' => 'Radiology Technician', 'description' => 'Operates imaging equipment and assists with diagnostic procedures'],
                ['name' => 'Physical Therapy Assistant', 'description' => 'Helps physical therapists with patient treatments and exercises'],
                ['name' => 'Medical Billing Specialist', 'description' => 'Processes insurance claims and manages patient billing'],
                ['name' => 'Unit Secretary', 'description' => 'Provides administrative support to medical units'],
            ],
            'lodging' => [
                ['name' => 'Front Desk Agent', 'description' => 'Manages guest check-ins, check-outs, and provides customer service'],
                ['name' => 'Housekeeper', 'description' => 'Cleans and maintains guest rooms and common areas'],
                ['name' => 'Bellhop', 'description' => 'Assists guests with luggage and provides concierge services'],
                ['name' => 'Concierge', 'description' => 'Provides guests with local information and arranges services'],
                ['name' => 'Maintenance Worker', 'description' => 'Performs repairs and maintains hotel facilities'],
                ['name' => 'Valet', 'description' => 'Parks guest vehicles and provides valet services'],
                ['name' => 'Room Service', 'description' => 'Delivers food and beverages to guest rooms'],
                ['name' => 'Banquet Server', 'description' => 'Serves food and beverages at hotel events and functions'],
                ['name' => 'Hotel Manager', 'description' => 'Oversees all hotel operations and manages staff'],
                ['name' => 'Night Auditor', 'description' => 'Manages front desk during overnight hours and performs audit duties'],
            ],
            'automotive' => [
                ['name' => 'Automotive Technician', 'description' => 'Diagnoses and repairs vehicle mechanical issues'],
                ['name' => 'Service Advisor', 'description' => 'Consults with customers about vehicle services and repairs'],
                ['name' => 'Parts Counter Person', 'description' => 'Manages automotive parts inventory and assists customers'],
                ['name' => 'Lube Technician', 'description' => 'Performs oil changes and basic vehicle maintenance'],
                ['name' => 'Cashier', 'description' => 'Processes payments for automotive services and products'],
                ['name' => 'Car Wash Attendant', 'description' => 'Cleans vehicles using car wash equipment and hand detailing'],
                ['name' => 'Shop Manager', 'description' => 'Manages automotive shop operations and supervises staff'],
            ],
        ];

        foreach ($positionCategories as $industrySlug => $positions) {
            $industry = $industries->get($industrySlug);

            if (! $industry) {
                $this->command->warn("Industry '{$industrySlug}' not found, skipping positions");

                continue;
            }

            foreach ($positions as $positionData) {
                // Create unique slug by combining position name and industry slug
                $baseSlug = Str::slug($positionData['name']);
                $uniqueSlug = $baseSlug.'-'.$industry->slug;

                $position = PositionCategory::firstOrCreate([
                    'name' => $positionData['name'],
                    'industry_id' => $industry->id,
                ], [
                    'slug' => $uniqueSlug,
                    'description' => $positionData['description'],
                    'status' => 'active',
                ]);

                $this->command->info("Seeded position: {$position->name} (Industry: {$industry->name})");
            }
        }

        // Also seed positions for child industries by inheriting from parent
        $childIndustries = Industry::whereNotNull('parent_id')->with('parent')->get();

        foreach ($childIndustries as $childIndustry) {
            // Get positions from parent industry
            $parentPositions = PositionCategory::where('industry_id', $childIndustry->parent->id)->get();

            foreach ($parentPositions as $parentPosition) {
                $childPosition = PositionCategory::firstOrCreate([
                    'name' => $parentPosition->name,
                    'industry_id' => $childIndustry->id,
                ], [
                    'slug' => Str::slug($parentPosition->name.'-'.$childIndustry->slug),
                    'description' => $parentPosition->description,
                    'status' => 'active',
                ]);

                $this->command->info("Inherited position: {$childPosition->name} (Child Industry: {$childIndustry->name})");
            }
        }

        $totalPositions = PositionCategory::count();
        $this->command->info("Seeded {$totalPositions} total position categories");
    }
}
