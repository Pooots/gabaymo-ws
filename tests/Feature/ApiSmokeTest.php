<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiSmokeTest extends TestCase
{
    public function test_api_info_endpoint(): void
    {
        $this->getJson('/api')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('name', 'Gabaymo')
            ->assertJsonPath('service', 'gabaymo-api');
    }

    public function test_health_endpoint(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('database', 'connected')
            ->assertJsonStructure(['status', 'database', 'timestamp']);
    }

    public function test_v1_info_endpoint(): void
    {
        $this->getJson('/api/v1')
            ->assertOk()
            ->assertJsonPath('version', 'v1');
    }
}
