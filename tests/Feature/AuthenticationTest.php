<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * Test user can register successfully.
     */
    public function test_user_can_register(): void
    {
        $userData = [
            'name' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
            'username' => $this->faker->unique()->userName,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'phone' => $this->faker->phoneNumber,
            'birthday' => $this->faker->date,
            'city' => $this->faker->city,
            'state' => $this->faker->state,
            'country' => $this->faker->country,
            'zipcode' => $this->faker->postcode,
        ];

        $response = $this->postJson('/api/v1/auth/register', $userData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'username',
                        'role',
                        'enabled',
                    ],
                    'token',
                ],
            ])
            ->assertJson([
                'message' => 'User registered successfully',
                'data' => [
                    'user' => [
                        'name' => $userData['name'],
                        'email' => $userData['email'],
                        'username' => $userData['username'],
                        'role' => 'viewer',
                        'enabled' => true,
                    ],
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => $userData['email'],
            'username' => $userData['username'],
            'enabled' => true,
        ]);
    }

    /**
     * Test user registration requires valid data.
     */
    public function test_user_registration_requires_valid_data(): void
    {
        $response = $this->postJson('/api/v1/auth/register', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'username', 'password']);
    }

    /**
     * Test user registration requires unique email.
     */
    public function test_user_registration_requires_unique_email(): void
    {
        $existingUser = User::factory()->create();

        $userData = [
            'name' => $this->faker->name,
            'email' => $existingUser->email,
            'username' => $this->faker->unique()->userName,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->postJson('/api/v1/auth/register', $userData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * Test user registration requires unique username.
     */
    public function test_user_registration_requires_unique_username(): void
    {
        $existingUser = User::factory()->create();

        $userData = [
            'name' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
            'username' => $existingUser->username,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->postJson('/api/v1/auth/register', $userData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['username']);
    }

    /**
     * Test user can login with valid credentials.
     */
    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'password123',
            'enabled' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_name' => 'Test Device',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'username',
                        'role',
                        'enabled',
                    ],
                    'token',
                ],
            ])
            ->assertJson([
                'message' => 'Login successful',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'username' => $user->username,
                        'role' => $user->role,
                        'enabled' => true,
                    ],
                ],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    /**
     * Test user cannot login with invalid credentials.
     */
    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'password123',
            'enabled' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * Test disabled user cannot login.
     */
    public function test_disabled_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'password123',
            'enabled' => false,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * Test user login requires valid data.
     */
    public function test_user_login_requires_valid_data(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    /**
     * Test authenticated user can access their profile.
     */
    public function test_authenticated_user_can_access_profile(): void
    {
        $user = User::factory()->create([
            'phone' => '555-1234',
            'birthday' => '1990-01-01',
            'city' => 'Test City',
            'state' => 'Test State',
            'country' => 'Test Country',
            'zipcode' => '12345',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'username',
                        'role',
                        'enabled',
                        'phone',
                        'birthday',
                        'city',
                        'state',
                        'country',
                        'zipcode',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ])
            ->assertJson([
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'username' => $user->username,
                        'role' => $user->role,
                        'enabled' => $user->enabled,
                        'phone' => $user->phone,
                        'birthday' => $user->birthday->toDateString(),
                        'city' => $user->city,
                        'state' => $user->state,
                        'country' => $user->country,
                        'zipcode' => $user->zipcode,
                    ],
                ],
            ]);
    }

    /**
     * Test unauthenticated user cannot access profile.
     */
    public function test_unauthenticated_user_cannot_access_profile(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    /**
     * Test authenticated user can logout.
     */
    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create([
            'email' => 'logout-test@example.com',
            'password' => 'password123',
            'enabled' => true,
        ]);

        // Login to get a token
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'logout-test@example.com',
            'password' => 'password123',
            'device_name' => 'Logout Test Device',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('data.token');

        // Logout using the token
        $logoutResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v1/auth/logout');

        $logoutResponse->assertStatus(200)
            ->assertJson([
                'message' => 'Logged out successfully',
            ]);

        // Verify token is no longer valid - use a fresh request
        $this->refreshApplication();

        $profileResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/auth/me');

        $profileResponse->assertStatus(401);
    }

    /**
     * Test unauthenticated user cannot logout.
     */
    public function test_unauthenticated_user_cannot_logout(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(401);
    }

    /**
     * Test complete authentication flow.
     */
    public function test_complete_authentication_flow(): void
    {
        // Register a new user
        $userData = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'username' => 'testuser',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $registerResponse = $this->postJson('/api/v1/auth/register', $userData);
        $registerResponse->assertStatus(201);

        // Login with the registered user (creates new token)
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_name' => 'Test Login Device',
        ]);

        $loginResponse->assertStatus(200);
        $loginToken = $loginResponse->json('data.token');

        // Access profile with login token
        $profileResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$loginToken,
        ])->getJson('/api/v1/auth/me');

        $profileResponse->assertStatus(200)
            ->assertJson([
                'data' => [
                    'user' => [
                        'email' => 'test@example.com',
                        'username' => 'testuser',
                    ],
                ],
            ]);

        // Logout using the login token
        $logoutResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$loginToken,
        ])->postJson('/api/v1/auth/logout');

        $logoutResponse->assertStatus(200);

        // Verify the login token is no longer valid - use a fresh request
        $this->refreshApplication();

        $invalidTokenResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$loginToken,
        ])->getJson('/api/v1/auth/me');

        $invalidTokenResponse->assertStatus(401);
    }
}
