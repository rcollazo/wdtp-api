<?php

namespace Tests\Unit;

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_admin_user_when_none_exists(): void
    {
        // Set config values
        Config::set('app.seed_admin_email', 'test-admin@wdtp.local');
        Config::set('app.seed_admin_password', 'test-password');

        // Ensure no admin users exist
        $this->assertEquals(0, User::count());

        // Run the seeder
        $this->seed(AdminSeeder::class);

        // Assert admin user was created
        $this->assertEquals(1, User::count());

        $admin = User::first();
        $this->assertEquals('test-admin@wdtp.local', $admin->email);
        $this->assertEquals('admin', $admin->role);
        $this->assertEquals('System Administrator', $admin->name);
        $this->assertEquals('admin', $admin->username);
        $this->assertTrue($admin->enabled);
        $this->assertTrue(Hash::check('test-password', $admin->password));
    }

    public function test_does_not_create_admin_when_admin_email_exists(): void
    {
        // Set config values
        Config::set('app.seed_admin_email', 'existing-admin@wdtp.local');
        Config::set('app.seed_admin_password', 'test-password');

        // Create existing admin with the same email
        User::factory()->create([
            'email' => 'existing-admin@wdtp.local',
            'role' => 'admin',
        ]);

        $this->assertEquals(1, User::count());

        // Run the seeder
        $this->seed(AdminSeeder::class);

        // Assert no additional user was created
        $this->assertEquals(1, User::count());
    }

    public function test_does_not_create_admin_when_admin_role_exists(): void
    {
        // Set config values
        Config::set('app.seed_admin_email', 'new-admin@wdtp.local');
        Config::set('app.seed_admin_password', 'test-password');

        // Create existing admin with different email but admin role
        User::factory()->create([
            'email' => 'different-admin@wdtp.local',
            'role' => 'admin',
        ]);

        $this->assertEquals(1, User::count());

        // Run the seeder
        $this->seed(AdminSeeder::class);

        // Assert no additional user was created
        $this->assertEquals(1, User::count());
    }

    public function test_handles_missing_config_gracefully(): void
    {
        // Clear config values
        Config::set('app.seed_admin_email', null);
        Config::set('app.seed_admin_password', null);

        $this->assertEquals(0, User::count());

        // Run the seeder (should handle missing config gracefully)
        $this->seed(AdminSeeder::class);

        // Assert no user was created
        $this->assertEquals(0, User::count());
    }

    public function test_does_not_create_admin_when_username_taken(): void
    {
        // Set config values
        Config::set('app.seed_admin_email', 'new-admin@wdtp.local');
        Config::set('app.seed_admin_password', 'test-password');

        // Create existing user with the username 'admin' but different role
        User::factory()->create([
            'username' => 'admin',
            'email' => 'other-user@wdtp.local',
            'role' => 'viewer',
        ]);

        $this->assertEquals(1, User::count());

        // Run the seeder
        $this->seed(AdminSeeder::class);

        // Assert no additional user was created
        $this->assertEquals(1, User::count());
    }
}
