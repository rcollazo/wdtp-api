<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminBootstrapTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Set consistent test config values
        Config::set('app.seed_admin_email', 'bootstrap-admin@wdtp.test');
        Config::set('app.seed_admin_password', 'bootstrap-test-password');

        // Clean up any existing test admin users to ensure clean state
        User::where('email', 'bootstrap-admin@wdtp.test')->delete();
        User::where('username', 'admin')->where('role', 'admin')->delete();
    }

    /**
     * Test AdminSeeder creates admin user on first run
     */
    public function test_admin_seeder_creates_admin_user_on_first_run(): void
    {
        // Ensure clean state
        $this->assertEquals(0, User::count());

        // Run seeder for first time
        $this->artisan('db:seed', ['--class' => AdminSeeder::class])
            ->assertExitCode(0);

        // Verify admin user was created
        $this->assertEquals(1, User::count());

        $admin = User::first();
        $this->assertEquals('bootstrap-admin@wdtp.test', $admin->email);
        $this->assertEquals('admin', $admin->role);
        $this->assertEquals('System Administrator', $admin->name);
        $this->assertEquals('admin', $admin->username);
        $this->assertTrue($admin->enabled);
        $this->assertTrue(Hash::check('bootstrap-test-password', $admin->password));
    }

    /**
     * Test AdminSeeder is idempotent (second run doesn't create duplicate)
     */
    public function test_admin_seeder_idempotency_no_duplicate_on_second_run(): void
    {
        // First run
        $this->artisan('db:seed', ['--class' => AdminSeeder::class]);
        $this->assertEquals(1, User::count());

        $firstAdmin = User::first();
        $originalId = $firstAdmin->id;
        $originalCreatedAt = $firstAdmin->created_at;

        // Second run - should not create duplicate
        $this->artisan('db:seed', ['--class' => AdminSeeder::class]);

        // Verify still only one admin user exists
        $this->assertEquals(1, User::count());

        // Verify it's the same user (not recreated)
        $admin = User::first();
        $this->assertEquals($originalId, $admin->id);
        $this->assertEquals($originalCreatedAt, $admin->created_at);
        $this->assertEquals('bootstrap-admin@wdtp.test', $admin->email);
        $this->assertEquals('admin', $admin->role);
    }

    /**
     * Test admin:ensure command creates admin user on first run
     */
    public function test_admin_ensure_command_creates_admin_user_on_first_run(): void
    {
        // Ensure clean state
        $this->assertEquals(0, User::count());

        // Run command for first time
        $this->artisan('admin:ensure')
            ->expectsOutput('Admin user created successfully with email: bootstrap-admin@wdtp.test')
            ->assertExitCode(0);

        // Verify admin user was created
        $this->assertEquals(1, User::count());

        $admin = User::first();
        $this->assertEquals('bootstrap-admin@wdtp.test', $admin->email);
        $this->assertEquals('admin', $admin->role);
        $this->assertEquals('System Administrator', $admin->name);
        $this->assertEquals('admin', $admin->username);
        $this->assertTrue($admin->enabled);
        $this->assertTrue(Hash::check('bootstrap-test-password', $admin->password));
    }

    /**
     * Test admin:ensure command is idempotent (second run doesn't create duplicate)
     */
    public function test_admin_ensure_command_idempotency_no_duplicate_on_second_run(): void
    {
        // First run
        $this->artisan('admin:ensure')
            ->expectsOutput('Admin user created successfully with email: bootstrap-admin@wdtp.test')
            ->assertExitCode(0);

        $this->assertEquals(1, User::count());
        $firstAdmin = User::first();
        $originalId = $firstAdmin->id;
        $originalCreatedAt = $firstAdmin->created_at;

        // Second run - should detect existing admin
        $this->artisan('admin:ensure')
            ->expectsOutput('Admin user already exists with email: bootstrap-admin@wdtp.test')
            ->assertExitCode(0);

        // Verify still only one admin user exists
        $this->assertEquals(1, User::count());

        // Verify it's the same user (not recreated)
        $admin = User::first();
        $this->assertEquals($originalId, $admin->id);
        $this->assertEquals($originalCreatedAt, $admin->created_at);
    }

    /**
     * Test multiple runs of AdminSeeder remain idempotent
     */
    public function test_admin_seeder_multiple_runs_remain_idempotent(): void
    {
        // Run seeder 5 times
        for ($i = 1; $i <= 5; $i++) {
            $this->artisan('db:seed', ['--class' => AdminSeeder::class]);

            // Should always have exactly 1 admin user
            $this->assertEquals(1, User::count(), "After run {$i}, should have exactly 1 user");

            $admin = User::first();
            $this->assertEquals('admin', $admin->role, "After run {$i}, user should have admin role");
            $this->assertEquals('bootstrap-admin@wdtp.test', $admin->email, "After run {$i}, email should be correct");
        }
    }

    /**
     * Test multiple runs of admin:ensure command remain idempotent
     */
    public function test_admin_ensure_command_multiple_runs_remain_idempotent(): void
    {
        // First run creates admin
        $this->artisan('admin:ensure')
            ->expectsOutput('Admin user created successfully with email: bootstrap-admin@wdtp.test')
            ->assertExitCode(0);

        // Next 4 runs should detect existing admin
        for ($i = 2; $i <= 5; $i++) {
            $this->artisan('admin:ensure')
                ->expectsOutput('Admin user already exists with email: bootstrap-admin@wdtp.test')
                ->assertExitCode(0);

            // Should always have exactly 1 admin user
            $this->assertEquals(1, User::count(), "After run {$i}, should have exactly 1 user");

            $admin = User::first();
            $this->assertEquals('admin', $admin->role, "After run {$i}, user should have admin role");
            $this->assertEquals('bootstrap-admin@wdtp.test', $admin->email, "After run {$i}, email should be correct");
        }
    }

    /**
     * Test AdminSeeder handles missing config gracefully
     */
    public function test_admin_seeder_handles_missing_config_gracefully(): void
    {
        // Clear config
        Config::set('app.seed_admin_email', null);
        Config::set('app.seed_admin_password', null);

        $this->assertEquals(0, User::count());

        // Run seeder - should handle gracefully without creating user
        $this->artisan('db:seed', ['--class' => AdminSeeder::class]);

        // No user should be created
        $this->assertEquals(0, User::count());
    }

    /**
     * Test admin:ensure command handles missing config gracefully
     */
    public function test_admin_ensure_command_handles_missing_config_gracefully(): void
    {
        // Clear config
        Config::set('app.seed_admin_email', null);
        Config::set('app.seed_admin_password', null);

        $this->assertEquals(0, User::count());

        // Run command - should fail with proper error message
        $this->artisan('admin:ensure')
            ->expectsOutput('Admin credentials not configured. Set APP_SEED_ADMIN_EMAIL and APP_SEED_ADMIN_PASSWORD in your .env file.')
            ->assertExitCode(1);

        // No user should be created
        $this->assertEquals(0, User::count());
    }

    /**
     * Test AdminSeeder doesn't create admin when admin role already exists
     */
    public function test_admin_seeder_skips_when_admin_role_exists(): void
    {
        // Create existing admin with different email
        User::factory()->create([
            'email' => 'existing-admin@wdtp.test',
            'role' => 'admin',
            'username' => 'existing-admin',
        ]);

        $this->assertEquals(1, User::count());

        // Run seeder - should detect existing admin by role
        $this->artisan('db:seed', ['--class' => AdminSeeder::class]);

        // Should not create additional user
        $this->assertEquals(1, User::count());

        // Verify original admin still exists
        $this->assertDatabaseHas('users', [
            'email' => 'existing-admin@wdtp.test',
            'role' => 'admin',
        ]);
    }

    /**
     * Test admin:ensure command doesn't create admin when admin role already exists
     */
    public function test_admin_ensure_command_skips_when_admin_role_exists(): void
    {
        // Create existing admin with different email
        User::factory()->create([
            'email' => 'existing-admin@wdtp.test',
            'role' => 'admin',
            'username' => 'existing-admin',
        ]);

        $this->assertEquals(1, User::count());

        // Run command - should detect existing admin by role
        $this->artisan('admin:ensure')
            ->expectsOutput('An admin user already exists in the system.')
            ->assertExitCode(0);

        // Should not create additional user
        $this->assertEquals(1, User::count());

        // Verify original admin still exists
        $this->assertDatabaseHas('users', [
            'email' => 'existing-admin@wdtp.test',
            'role' => 'admin',
        ]);
    }

    /**
     * Test cross-idempotency: seeder then command
     */
    public function test_cross_idempotency_seeder_then_command(): void
    {
        // First run seeder
        $this->artisan('db:seed', ['--class' => AdminSeeder::class]);
        $this->assertEquals(1, User::count());

        $admin = User::first();
        $originalId = $admin->id;

        // Then run command - should detect existing admin
        $this->artisan('admin:ensure')
            ->expectsOutput('Admin user already exists with email: bootstrap-admin@wdtp.test')
            ->assertExitCode(0);

        // Still only one user
        $this->assertEquals(1, User::count());
        $this->assertEquals($originalId, User::first()->id);
    }

    /**
     * Test cross-idempotency: command then seeder
     */
    public function test_cross_idempotency_command_then_seeder(): void
    {
        // First run command
        $this->artisan('admin:ensure');
        $this->assertEquals(1, User::count());

        $admin = User::first();
        $originalId = $admin->id;

        // Then run seeder - should detect existing admin
        $this->artisan('db:seed', ['--class' => AdminSeeder::class]);

        // Still only one user
        $this->assertEquals(1, User::count());
        $this->assertEquals($originalId, User::first()->id);
    }

    /**
     * Test admin user properties are correct after creation
     */
    public function test_created_admin_has_correct_properties(): void
    {
        $this->artisan('admin:ensure');

        $admin = User::first();

        // Verify all expected properties
        $this->assertEquals('System Administrator', $admin->name);
        $this->assertEquals('bootstrap-admin@wdtp.test', $admin->email);
        $this->assertEquals('admin', $admin->username);
        $this->assertEquals('admin', $admin->role);
        $this->assertTrue($admin->enabled);
        $this->assertTrue(Hash::check('bootstrap-test-password', $admin->password));
        $this->assertNotNull($admin->created_at);
        $this->assertNotNull($admin->updated_at);
    }

    /**
     * Test both seeder and command handle username conflicts properly
     */
    public function test_username_conflict_handling(): void
    {
        // Create a user with username 'admin' but different role
        User::factory()->create([
            'username' => 'admin',
            'email' => 'other-user@wdtp.test',
            'role' => 'viewer',
        ]);

        $this->assertEquals(1, User::count());

        // Both seeder and command should handle this gracefully
        $this->artisan('db:seed', ['--class' => AdminSeeder::class]);
        $this->assertEquals(1, User::count()); // No additional user created

        $this->artisan('admin:ensure')
            ->expectsOutput('Username "admin" is already taken by another user.')
            ->assertExitCode(1);
        $this->assertEquals(1, User::count()); // Still no additional user
    }
}
