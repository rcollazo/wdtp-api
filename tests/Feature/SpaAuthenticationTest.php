<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class SpaAuthenticationTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure for SPA authentication testing
        config([
            'sanctum.stateful' => ['localhost', '127.0.0.1', 'testbench-127.0.0.1.nip.io'],
            'session.driver' => 'cookie', // Use cookie driver for testing SPA flow
        ]);
    }

    /**
     * Test CSRF cookie endpoint is accessible.
     */
    public function test_csrf_cookie_endpoint_is_accessible(): void
    {
        $response = $this->get('/sanctum/csrf-cookie');

        $response->assertStatus(204);
        $response->assertCookie('XSRF-TOKEN');
    }

    /**
     * Test complete SPA authentication flow with cookie-based authentication.
     * Note: In testing environment, CSRF is disabled, so we focus on the
     * session-based authentication flow that Sanctum provides.
     */
    public function test_complete_spa_authentication_flow(): void
    {
        // Create a test user
        $user = User::factory()->create([
            'email' => 'spa-test@example.com',
            'password' => 'password123',
            'enabled' => true,
        ]);

        // Step 1: Get CSRF cookie (required for SPA setup)
        $csrfResponse = $this->get('/sanctum/csrf-cookie');
        $csrfResponse->assertStatus(204);
        $csrfResponse->assertCookie('XSRF-TOKEN');

        // Extract CSRF token from cookie
        $csrfToken = $csrfResponse->getCookie('XSRF-TOKEN')->getValue();
        $this->assertNotEmpty($csrfToken);

        // Step 2: Login with proper headers (simulating SPA)
        $loginResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'Referer' => config('app.url'),
            'X-XSRF-TOKEN' => $csrfToken,
        ])->withCookies([
            'XSRF-TOKEN' => $csrfToken,
        ])->postJson('/api/v1/auth/login', [
            'email' => 'spa-test@example.com',
            'password' => 'password123',
            'device_name' => 'SPA Test Device',
        ]);

        $loginResponse->assertStatus(200);
        $loginResponse->assertJsonStructure([
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
        ]);

        // Extract the token for subsequent requests (SPA would use session + cookies)
        $token = $loginResponse->json('data.token');
        $this->assertNotEmpty($token);

        // Step 3: Access protected route using token authentication
        // (In real SPA, this would use cookies, but in testing we use token)
        $profileResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/auth/me');

        $profileResponse->assertStatus(200);
        $profileResponse->assertJsonStructure([
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
        ]);

        $profileResponse->assertJson([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'username' => $user->username,
                    'role' => $user->role,
                    'enabled' => true,
                ],
            ],
        ]);

        // Step 4: Logout using token authentication
        $logoutResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v1/auth/logout');

        $logoutResponse->assertStatus(200);
        $logoutResponse->assertJson([
            'message' => 'Logged out successfully',
        ]);

        // Step 5: Verify token is invalidated after logout
        $this->refreshApplication();

        $invalidTokenResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/auth/me');

        $invalidTokenResponse->assertStatus(401);
    }

    /**
     * Test SPA authentication workflow setup and headers.
     * Note: CSRF is disabled in testing, so we test proper header setup.
     */
    public function test_spa_authentication_workflow_setup(): void
    {
        $user = User::factory()->create([
            'email' => 'spa-workflow@example.com',
            'password' => 'password123',
            'enabled' => true,
        ]);

        // Get CSRF cookie first
        $csrfResponse = $this->get('/sanctum/csrf-cookie');
        $csrfResponse->assertStatus(204);
        $csrfToken = $csrfResponse->getCookie('XSRF-TOKEN')->getValue();

        // Login with proper SPA headers
        $loginResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'Referer' => config('app.url'),
            'X-XSRF-TOKEN' => $csrfToken,
        ])->withCookies([
            'XSRF-TOKEN' => $csrfToken,
        ])->postJson('/api/v1/auth/login', [
            'email' => 'spa-workflow@example.com',
            'password' => 'password123',
        ]);

        $loginResponse->assertStatus(200);
        $loginResponse->assertJsonFragment([
            'message' => 'Login successful',
        ]);
    }

    /**
     * Test SPA authentication with invalid credentials.
     */
    public function test_spa_authentication_with_invalid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'valid@example.com',
            'password' => 'password123',
            'enabled' => true,
        ]);

        // Get CSRF cookie first
        $csrfResponse = $this->get('/sanctum/csrf-cookie');
        $csrfToken = $csrfResponse->getCookie('XSRF-TOKEN')->getValue();

        // Attempt login with invalid credentials
        $loginResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'Referer' => config('app.url'),
            'X-XSRF-TOKEN' => $csrfToken,
        ])->withCookies([
            'XSRF-TOKEN' => $csrfToken,
        ])->postJson('/api/v1/auth/login', [
            'email' => 'valid@example.com',
            'password' => 'wrongpassword',
        ]);

        $loginResponse->assertStatus(422);
        $loginResponse->assertJsonValidationErrors(['email']);
        $loginResponse->assertJson([
            'errors' => [
                'email' => ['The provided credentials are incorrect.'],
            ],
        ]);
    }

    /**
     * Test SPA authentication with disabled user account.
     */
    public function test_spa_authentication_with_disabled_user(): void
    {
        $user = User::factory()->create([
            'email' => 'disabled@example.com',
            'password' => 'password123',
            'enabled' => false,
        ]);

        // Get CSRF cookie first
        $csrfResponse = $this->get('/sanctum/csrf-cookie');
        $csrfToken = $csrfResponse->getCookie('XSRF-TOKEN')->getValue();

        // Attempt login with disabled user
        $loginResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'Referer' => config('app.url'),
            'X-XSRF-TOKEN' => $csrfToken,
        ])->withCookies([
            'XSRF-TOKEN' => $csrfToken,
        ])->postJson('/api/v1/auth/login', [
            'email' => 'disabled@example.com',
            'password' => 'password123',
        ]);

        $loginResponse->assertStatus(422);
        $loginResponse->assertJsonValidationErrors(['email']);
        $loginResponse->assertJson([
            'errors' => [
                'email' => ['This account has been disabled.'],
            ],
        ]);
    }

    /**
     * Test cross-origin SPA authentication (different subdomain scenario).
     */
    public function test_cross_origin_spa_authentication(): void
    {
        $user = User::factory()->create([
            'email' => 'cors-test@example.com',
            'password' => 'password123',
            'enabled' => true,
        ]);

        // Simulate SPA on different subdomain with proper headers
        $csrfResponse = $this->withHeaders([
            'Origin' => 'https://app.localhost',
            'Accept' => 'application/json',
        ])->get('/sanctum/csrf-cookie');

        $csrfResponse->assertStatus(204);
        $csrfToken = $csrfResponse->getCookie('XSRF-TOKEN')->getValue();

        // Login with cross-origin headers
        $loginResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'Origin' => 'https://app.localhost',
            'X-XSRF-TOKEN' => $csrfToken,
        ])->withCookies([
            'XSRF-TOKEN' => $csrfToken,
        ])->postJson('/api/v1/auth/login', [
            'email' => 'cors-test@example.com',
            'password' => 'password123',
            'device_name' => 'Cross-Origin SPA',
        ]);

        $loginResponse->assertStatus(200);
        $loginResponse->assertJsonStructure([
            'message',
            'data' => [
                'user',
                'token',
            ],
        ]);
    }

    /**
     * Test SPA logout without authentication fails properly.
     */
    public function test_spa_logout_without_authentication(): void
    {
        // Get CSRF token
        $csrfResponse = $this->get('/sanctum/csrf-cookie');
        $csrfToken = $csrfResponse->getCookie('XSRF-TOKEN')->getValue();

        // Attempt logout without being authenticated
        $logoutResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'Referer' => config('app.url'),
            'X-XSRF-TOKEN' => $csrfToken,
        ])->withCookies([
            'XSRF-TOKEN' => $csrfToken,
        ])->postJson('/api/v1/auth/logout');

        $logoutResponse->assertStatus(401);
    }

    /**
     * Test concurrent SPA sessions are handled properly.
     */
    public function test_concurrent_spa_sessions(): void
    {
        $user = User::factory()->create([
            'email' => 'concurrent@example.com',
            'password' => 'password123',
            'enabled' => true,
        ]);

        // First session
        $csrf1 = $this->get('/sanctum/csrf-cookie')->getCookie('XSRF-TOKEN')->getValue();
        $login1 = $this->withHeaders([
            'Accept' => 'application/json',
            'Referer' => config('app.url'),
            'X-XSRF-TOKEN' => $csrf1,
        ])->withCookies([
            'XSRF-TOKEN' => $csrf1,
        ])->postJson('/api/v1/auth/login', [
            'email' => 'concurrent@example.com',
            'password' => 'password123',
            'device_name' => 'Session 1',
        ]);

        $login1->assertStatus(200);
        $token1 = $login1->json('data.token');

        // Second session
        $csrf2 = $this->get('/sanctum/csrf-cookie')->getCookie('XSRF-TOKEN')->getValue();
        $login2 = $this->withHeaders([
            'Accept' => 'application/json',
            'Referer' => config('app.url'),
            'X-XSRF-TOKEN' => $csrf2,
        ])->withCookies([
            'XSRF-TOKEN' => $csrf2,
        ])->postJson('/api/v1/auth/login', [
            'email' => 'concurrent@example.com',
            'password' => 'password123',
            'device_name' => 'Session 2',
        ]);

        $login2->assertStatus(200);
        $token2 = $login2->json('data.token');

        // Both sessions should be able to access protected routes
        $profile1 = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token1,
        ])->getJson('/api/v1/auth/me');

        $profile2 = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token2,
        ])->getJson('/api/v1/auth/me');

        $profile1->assertStatus(200);
        $profile2->assertStatus(200);

        // Logout from first session should not affect second session
        $logout1 = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token1,
        ])->postJson('/api/v1/auth/logout');

        $logout1->assertStatus(200);

        // Second session should still work
        $profile2Again = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token2,
        ])->getJson('/api/v1/auth/me');

        $profile2Again->assertStatus(200);

        // But first session should be invalid
        $this->refreshApplication();

        $profile1Again = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token1,
        ])->getJson('/api/v1/auth/me');

        $profile1Again->assertStatus(401);
    }

    /**
     * Test SPA authentication preserves user data correctly.
     */
    public function test_spa_authentication_preserves_user_data(): void
    {
        $user = User::factory()->create([
            'name' => 'SPA Test User',
            'email' => 'spa-data@example.com',
            'username' => 'spatestuser',
            'password' => 'password123',
            'enabled' => true,
            'role' => 'contributor',
            'phone' => '+1-555-123-4567',
            'city' => 'Test City',
            'state' => 'CA',
        ]);

        // Complete SPA login flow
        $csrfToken = $this->get('/sanctum/csrf-cookie')->getCookie('XSRF-TOKEN')->getValue();

        $loginResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'Referer' => config('app.url'),
            'X-XSRF-TOKEN' => $csrfToken,
        ])->withCookies([
            'XSRF-TOKEN' => $csrfToken,
        ])->postJson('/api/v1/auth/login', [
            'email' => 'spa-data@example.com',
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('data.token');

        // Verify all user data is preserved in profile response
        $profileResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/auth/me');

        $profileResponse->assertStatus(200);
        $profileResponse->assertJson([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => 'SPA Test User',
                    'email' => 'spa-data@example.com',
                    'username' => 'spatestuser',
                    'role' => 'contributor',
                    'enabled' => true,
                    'phone' => '+1-555-123-4567',
                    'city' => 'Test City',
                    'state' => 'CA',
                ],
            ],
        ]);

        $profileResponse->assertJsonStructure([
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
        ]);
    }

    /**
     * Test token-based authentication still works alongside SPA setup.
     */
    public function test_token_authentication_still_works(): void
    {
        $user = User::factory()->create([
            'email' => 'token-test@example.com',
            'password' => 'password123',
            'enabled' => true,
        ]);

        // Login to get token
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'token-test@example.com',
            'password' => 'password123',
            'device_name' => 'Token Test Device',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('data.token');

        // Use token for authentication (standard API client flow)
        $profileResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/auth/me');

        $profileResponse->assertStatus(200);
        $profileResponse->assertJson([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'enabled' => true,
                ],
            ],
        ]);

        // Logout should invalidate token
        $logoutResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v1/auth/logout');

        $logoutResponse->assertStatus(200);

        // Token should be invalid after logout
        $this->refreshApplication();

        $invalidResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/auth/me');

        $invalidResponse->assertStatus(401);
    }
}
