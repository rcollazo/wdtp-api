<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test AdminSeeder creates admin user with environment variables.
     */
    public function test_admin_seeder_creates_admin_user_with_env_vars(): void
    {
        // Set environment variables
        config([
            'app.seed_admin_email' => 'admin@wdtp.test',
            'app.seed_admin_password' => 'admin123',
        ]);

        // Run the seeder
        $this->artisan('db:seed', ['--class' => AdminSeeder::class]);

        // Verify admin user was created
        $this->assertDatabaseHas('users', [
            'name' => 'admin',
            'email' => 'admin@wdtp.test',
            'username' => 'admin',
            'role' => 'admin',
            'enabled' => true,
        ]);

        // Verify password is correct
        $admin = User::where('email', 'admin@wdtp.test')->first();
        $this->assertTrue(\Hash::check('admin123', $admin->password));
    }

    /**
     * Test AdminSeeder uses default values when environment variables are not set.
     */
    public function test_admin_seeder_uses_defaults_when_env_vars_not_set(): void
    {
        // Clear environment variables
        config([
            'app.seed_admin_email' => null,
            'app.seed_admin_password' => null,
        ]);

        // Run the seeder
        $this->artisan('db:seed', ['--class' => AdminSeeder::class]);

        // Verify admin user was created with defaults
        $this->assertDatabaseHas('users', [
            'name' => 'admin',
            'email' => 'admin@wdtp.local',
            'username' => 'admin',
            'role' => 'admin',
            'enabled' => true,
        ]);

        // Verify default password is correct
        $admin = User::where('email', 'admin@wdtp.local')->first();
        $this->assertTrue(\Hash::check('password', $admin->password));
    }

    /**
     * Test AdminSeeder doesn't create duplicate admin users.
     */
    public function test_admin_seeder_does_not_create_duplicate_admin_users(): void
    {
        // Set environment variables
        config([
            'app.seed_admin_email' => 'admin@wdtp.test',
            'app.seed_admin_password' => 'admin123',
        ]);

        // Run the seeder twice
        $this->artisan('db:seed', ['--class' => AdminSeeder::class]);
        $this->artisan('db:seed', ['--class' => AdminSeeder::class]);

        // Verify only one admin user exists
        $adminCount = User::where('email', 'admin@wdtp.test')->count();
        $this->assertEquals(1, $adminCount);
    }

    /**
     * Test admin user can login successfully.
     */
    public function test_admin_user_can_login(): void
    {
        // Set environment variables
        config([
            'app.seed_admin_email' => 'admin@wdtp.test',
            'app.seed_admin_password' => 'admin123',
        ]);

        // Run the seeder
        $this->artisan('db:seed', ['--class' => AdminSeeder::class]);

        // Test login
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@wdtp.test',
            'password' => 'admin123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Login successful',
                'data' => [
                    'user' => [
                        'name' => 'admin',
                        'email' => 'admin@wdtp.test',
                        'username' => 'admin',
                        'role' => 'admin',
                        'enabled' => true,
                    ],
                ],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
    }
}
