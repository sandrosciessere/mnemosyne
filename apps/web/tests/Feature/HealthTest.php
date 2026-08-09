<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_endpoint_responds(): void
    {
        $this->get('/health/live')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'service' => 'mnemosyne-web',
            ]);
    }

    public function test_readiness_endpoint_reports_healthy_checks(): void
    {
        $this->get('/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.db', 'ok')
            ->assertJsonPath('checks.storage', 'ok');
    }

    public function test_readiness_endpoint_fails_when_storage_is_unavailable(): void
    {
        config(['mnemosyne.data_path' => '/nonexistent-mnemosyne-path']);

        $this->get('/health/ready')
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.storage', 'failed');
    }

    public function test_versioned_api_health_endpoint_responds_without_authentication(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'service' => 'mnemosyne-api',
                'api_version' => 'v1',
            ]);
    }
}
