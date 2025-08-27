<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class EnsureAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:ensure';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ensure an admin user exists in the system';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $adminEmail = config('app.seed_admin_email');
        $adminPassword = config('app.seed_admin_password');

        // Handle missing config
        if (! $adminEmail || ! $adminPassword) {
            $this->error('Admin credentials not configured. Set APP_SEED_ADMIN_EMAIL and APP_SEED_ADMIN_PASSWORD in your .env file.');

            return 1;
        }

        // Check if any admin user already exists (by email or role)
        if (User::where('email', $adminEmail)->exists()) {
            $this->info("Admin user already exists with email: {$adminEmail}");

            return 0;
        }

        if (User::where('role', 'admin')->exists()) {
            $this->info('An admin user already exists in the system.');

            return 0;
        }

        // Check if username 'admin' is already taken
        if (User::where('username', 'admin')->exists()) {
            $this->error('Username "admin" is already taken by another user.');

            return 1;
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

            $this->info("Admin user created successfully with email: {$adminEmail}");

            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to create admin user: {$e->getMessage()}");

            return 1;
        }
    }
}
