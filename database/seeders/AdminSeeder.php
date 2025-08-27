<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminEmail = config('app.seed_admin_email');
        $adminPassword = config('app.seed_admin_password');

        // Handle missing config
        if (! $adminEmail || ! $adminPassword) {
            $this->command->error('Admin credentials not configured. Set APP_SEED_ADMIN_EMAIL and APP_SEED_ADMIN_PASSWORD in your .env file.');

            return;
        }

        // Check if any admin user already exists (by email or role)
        if (User::where('email', $adminEmail)->exists()) {
            $this->command->info("Admin user already exists with email: {$adminEmail}");

            return;
        }

        if (User::where('role', 'admin')->exists()) {
            $this->command->info('An admin user already exists in the system.');

            return;
        }

        // Check if username 'admin' is already taken
        if (User::where('username', 'admin')->exists()) {
            $this->command->error('Username "admin" is already taken by another user.');

            return;
        }

        // Create admin user
        try {
            $admin = User::create([
                'name' => 'System Administrator',
                'email' => $adminEmail,
                'username' => 'admin',
                'password' => Hash::make($adminPassword),
                'role' => 'admin',
                'enabled' => true,
            ]);

            $this->command->info("Admin user created successfully with email: {$adminEmail}");
        } catch (\Exception $e) {
            $this->command->error("Failed to create admin user: {$e->getMessage()}");
        }
    }
}
