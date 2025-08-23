<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminEmail = config('app.seed_admin_email') ?? 'admin@wdtp.local';
        $adminPassword = config('app.seed_admin_password') ?? 'password';

        // Check if admin user already exists
        if (User::where('email', $adminEmail)->exists()) {
            $this->command->info('Admin user already exists.');

            return;
        }

        // Create admin user
        $admin = User::create([
            'name' => 'admin',
            'email' => $adminEmail,
            'username' => 'admin',
            'password' => $adminPassword,
            'role' => 'admin',
            'enabled' => true,
        ]);

        $this->command->info("Admin user created successfully with email: {$adminEmail}");
    }
}
