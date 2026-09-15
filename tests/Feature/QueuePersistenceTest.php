<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\Delivery;
use App\Models\Endpoint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QueuePersistenceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $key = 'queue-test-key';

    private function setUpContext(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.connection' => config('database.default')]);
        $client = ApiClient::query()->create(['name' => 'queue-client', 'key_hash' => hash('sha256', $this->key)]);
        $endpoint = Endpoint::query()->create([
            'key' => 'secure', 'vendor' => 'Secure Vendor', 'url' => 'https://secure.test/hook',
            'static_headers' => ['Authorization' => 'Bearer must-not-leak'],
            'allowed_dynamic_headers' => [], 'backoff_seconds' => [1],
        ]);
        $client->endpoints()->attach($endpoint);
    }

    public function test_database_job_payload_contains_only_delivery_reference(): void
    {
        $this->setUpContext();

        $response = $this->withHeader('X-API-Key', $this->key)->postJson('/api/v1/deliveries', [
            'endpoint_key' => 'secure', 'idempotency_key' => 'secure-1',
            'payload' => ['secret' => 'must-not-leak'], 'content_type' => 'application/json',
        ])->assertAccepted();

        $payload = (string) \DB::table('jobs')->value('payload');
        $this->assertStringContainsString($response->json('id'), $payload);
        $this->assertStringNotContainsString('must-not-leak', $payload);
        $this->assertStringNotContainsString('Authorization', $payload);
    }

    public function test_queue_insert_failure_rolls_back_delivery(): void
    {
        $this->setUpContext();

        Schema::drop('jobs');

        $this->withHeader('X-API-Key', $this->key)->postJson('/api/v1/deliveries', [
            'endpoint_key' => 'secure', 'idempotency_key' => 'rollback-1',
            'payload' => ['value' => 1], 'content_type' => 'application/json',
        ])->assertServerError();

        $this->assertSame(0, Delivery::query()->where('idempotency_key', 'rollback-1')->count());
    }
}
