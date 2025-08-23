<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that user model has correct fillable attributes.
     */
    public function test_user_fillable_attributes(): void
    {
        $expectedFillable = [
            'name',
            'email',
            'password',
            'username',
            'role',
            'phone',
            'birthday',
            'city',
            'state',
            'country',
            'zipcode',
            'enabled',
        ];

        $user = new User();
        $this->assertEquals($expectedFillable, $user->getFillable());
    }

    /**
     * Test that user model has correct hidden attributes.
     */
    public function test_user_hidden_attributes(): void
    {
        $expectedHidden = [
            'password',
            'remember_token',
        ];

        $user = new User();
        $this->assertEquals($expectedHidden, $user->getHidden());
    }

    /**
     * Test that user model casts attributes correctly.
     */
    public function test_user_casts_attributes(): void
    {
        $user = User::factory()->create([
            'birthday' => '1990-01-15',
            'enabled' => true,
        ]);

        $this->assertInstanceOf(\Carbon\Carbon::class, $user->birthday);
        $this->assertTrue(is_bool($user->enabled));
        $this->assertInstanceOf(\Carbon\Carbon::class, $user->email_verified_at);
    }

    /**
     * Test user creation with all WDTP fields.
     */
    public function test_user_creation_with_wdtp_fields(): void
    {
        $userData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'username' => 'johndoe',
            'role' => 'contributor',
            'phone' => '+1234567890',
            'birthday' => '1990-01-15',
            'city' => 'New York',
            'state' => 'NY',
            'country' => 'USA',
            'zipcode' => '10001',
            'enabled' => true,
        ];

        $user = User::create($userData);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('John Doe', $user->name);
        $this->assertEquals('john@example.com', $user->email);
        $this->assertEquals('johndoe', $user->username);
        $this->assertEquals('contributor', $user->role);
        $this->assertEquals('+1234567890', $user->phone);
        $this->assertEquals('1990-01-15', $user->birthday->format('Y-m-d'));
        $this->assertEquals('New York', $user->city);
        $this->assertEquals('NY', $user->state);
        $this->assertEquals('USA', $user->country);
        $this->assertEquals('10001', $user->zipcode);
        $this->assertTrue($user->enabled);
    }

    /**
     * Test user role validation with valid roles.
     */
    public function test_user_role_validation(): void
    {
        $validRoles = ['admin', 'moderator', 'contributor', 'viewer'];

        foreach ($validRoles as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertEquals($role, $user->role);
        }
    }

    /**
     * Test user default values.
     */
    public function test_user_default_values(): void
    {
        $userData = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'username' => 'testuser_defaults',
            'city' => 'Test City',
            'state' => 'Test State',
            'country' => 'Test Country',
            'zipcode' => '12345',
        ];

        $user = User::create($userData);

        $this->assertEquals('viewer', $user->role);
        $this->assertFalse($user->enabled);
    }

    /**
     * Test user factory creates valid user.
     */
    public function test_user_factory_creates_valid_user(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->name);
        $this->assertNotNull($user->email);
        $this->assertNotNull($user->username);
        $this->assertContains($user->role, ['admin', 'moderator', 'contributor', 'viewer']);
        $this->assertNotNull($user->city);
        $this->assertNotNull($user->state);
        $this->assertNotNull($user->country);
        $this->assertNotNull($user->zipcode);
        $this->assertIsBool($user->enabled);
    }

    /**
     * Test user factory role states.
     */
    public function test_user_factory_role_states(): void
    {
        $adminUser = User::factory()->admin()->create();
        $this->assertEquals('admin', $adminUser->role);
        $this->assertTrue($adminUser->enabled);

        $moderatorUser = User::factory()->moderator()->create();
        $this->assertEquals('moderator', $moderatorUser->role);
        $this->assertTrue($moderatorUser->enabled);

        $contributorUser = User::factory()->contributor()->create();
        $this->assertEquals('contributor', $contributorUser->role);
        $this->assertTrue($contributorUser->enabled);

        $viewerUser = User::factory()->viewer()->create();
        $this->assertEquals('viewer', $viewerUser->role);
        $this->assertTrue($viewerUser->enabled);
    }

    /**
     * Test user factory enabled/disabled states.
     */
    public function test_user_factory_enabled_states(): void
    {
        $enabledUser = User::factory()->enabled()->create();
        $this->assertTrue($enabledUser->enabled);

        $disabledUser = User::factory()->disabled()->create();
        $this->assertFalse($disabledUser->enabled);
    }

    /**
     * Test username uniqueness constraint.
     */
    public function test_username_uniqueness(): void
    {
        User::factory()->create(['username' => 'testuser']);
        
        $this->expectException(\Illuminate\Database\QueryException::class);
        User::factory()->create(['username' => 'testuser']);
    }

    /**
     * Test nullable fields can be null.
     */
    public function test_nullable_fields(): void
    {
        $user = User::factory()->create([
            'phone' => null,
            'birthday' => null,
        ]);

        $this->assertNull($user->phone);
        $this->assertNull($user->birthday);
    }

    /**
     * Test required fields cannot be null.
     */
    public function test_required_fields_not_null(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->username);
        $this->assertNotNull($user->city);
        $this->assertNotNull($user->state);
        $this->assertNotNull($user->country);
        $this->assertNotNull($user->zipcode);
    }
}
