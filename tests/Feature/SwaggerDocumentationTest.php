<?php

namespace Tests\Feature;

use Tests\TestCase;

class SwaggerDocumentationTest extends TestCase
{
    public function test_swagger_documentation_route_is_accessible(): void
    {
        $response = $this->get('/api/documentation');

        $response->assertStatus(200);
        $response->assertSee('WDTP API Documentation');
    }

    public function test_swagger_json_is_generated(): void
    {
        $response = $this->get('/docs');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/json');

        $content = $response->getContent();
        $json = json_decode($content, true);

        $this->assertIsArray($json);
        $this->assertEquals('3.0.0', $json['openapi']);
        $this->assertEquals('WDTP API', $json['info']['title']);
        $this->assertEquals('1.0.0', $json['info']['version']);
    }

    public function test_authentication_endpoints_are_documented(): void
    {
        $response = $this->get('/docs');
        $json = json_decode($response->getContent(), true);

        $paths = $json['paths'];

        // Check that all auth endpoints are documented
        $this->assertArrayHasKey('/api/v1/auth/register', $paths);
        $this->assertArrayHasKey('/api/v1/auth/login', $paths);
        $this->assertArrayHasKey('/api/v1/auth/logout', $paths);
        $this->assertArrayHasKey('/api/v1/auth/me', $paths);

        // Check that register endpoint has proper documentation
        $register = $paths['/api/v1/auth/register']['post'];
        $this->assertEquals(['Authentication'], $register['tags']);
        $this->assertEquals('Register a new user account', $register['summary']);
        $this->assertArrayHasKey('201', $register['responses']);
        $this->assertArrayHasKey('422', $register['responses']);
    }

    public function test_health_endpoints_are_documented(): void
    {
        $response = $this->get('/docs');
        $json = json_decode($response->getContent(), true);

        $paths = $json['paths'];

        // Check that health endpoints are documented
        $this->assertArrayHasKey('/api/v1/healthz', $paths);
        $this->assertArrayHasKey('/api/v1/healthz/deep', $paths);

        // Check that endpoints have proper tags
        $basic = $paths['/api/v1/healthz']['get'];
        $this->assertEquals(['Health'], $basic['tags']);

        $deep = $paths['/api/v1/healthz/deep']['get'];
        $this->assertEquals(['Health'], $deep['tags']);
    }

    public function test_sanctum_security_scheme_is_configured(): void
    {
        $response = $this->get('/docs');
        $json = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('components', $json);
        $this->assertArrayHasKey('securitySchemes', $json['components']);
        $this->assertArrayHasKey('sanctum', $json['components']['securitySchemes']);

        $sanctum = $json['components']['securitySchemes']['sanctum'];
        $this->assertEquals('apiKey', $sanctum['type']);
        $this->assertEquals('header', $sanctum['in']);
        $this->assertEquals('Authorization', $sanctum['name']);
    }

    public function test_user_schemas_are_defined(): void
    {
        $response = $this->get('/docs');
        $json = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('components', $json);
        $this->assertArrayHasKey('schemas', $json['components']);

        $schemas = $json['components']['schemas'];
        $this->assertArrayHasKey('User', $schemas);
        $this->assertArrayHasKey('UserPublic', $schemas);
        $this->assertArrayHasKey('AuthResponse', $schemas);
        $this->assertArrayHasKey('ValidationError', $schemas);
        $this->assertArrayHasKey('ErrorResponse', $schemas);
    }
}
