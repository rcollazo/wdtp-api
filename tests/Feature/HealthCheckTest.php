<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_basic_health_check(): void
    {
        $response = $this->get('/api/v1/healthz');
        $response->assertStatus(200);
    }

    public function test_deep_health_check(): void
    {
        $response = $this->get('/api/v1/healthz/deep');
        
        if ($response->status() !== 200) {
            dump('Response status: ' . $response->status());
            dump('Response content: ' . $response->content());
        }
        
        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'database',
                     'postgis'
                 ]);
    }
}