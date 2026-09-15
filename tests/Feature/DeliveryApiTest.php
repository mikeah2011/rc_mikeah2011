<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Jobs\DeliverNotification;
use App\Models\ApiClient;
use App\Models\Delivery;
use App\Models\Endpoint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeliveryApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ApiClient $client;

    private Endpoint $endpoint;

    private string $apiKey = 'test-api-key';

    private function setUpContext(): void
    {
        $this->client = ApiClient::query()->create([
            'name' => 'orders',
            'key_hash' => hash('sha256', $this->apiKey),
            'is_active' => true,
        ]);
        $this->endpoint = Endpoint::query()->create([
            'key' => 'inventory-primary',
            'vendor' => 'Inventory Inc',
            'url' => 'https://inventory.example.test/stock',
            'method' => 'POST',
            'static_headers' => ['Authorization' => 'Bearer super-secret'],
            'allowed_dynamic_headers' => ['x-correlation-id'],
            'timeout_seconds' => 10,
            'max_attempts' => 3,
            'backoff_seconds' => [1, 5, 30],
            'is_active' => true,
        ]);
        $this->client->endpoints()->attach($this->endpoint);
    }

    public function test_authentication_is_required_and_disabled_clients_are_rejected(): void
    {
        $this->setUpContext();

        $this->postJson('/api/v1/deliveries', [])->assertUnauthorized();

        $this->client->update(['is_active' => false]);
        $this->withApiKey()->postJson('/api/v1/deliveries', [])->assertUnauthorized();
    }

    public function test_client_cannot_use_an_ungranted_endpoint(): void
    {
        $this->setUpContext();

        $other = Endpoint::query()->create([
            'key' => 'crm', 'vendor' => 'CRM', 'url' => 'https://crm.example.test',
            'static_headers' => [], 'allowed_dynamic_headers' => [], 'backoff_seconds' => [1],
        ]);

        $this->withApiKey()->postJson('/api/v1/deliveries', $this->request(['endpoint_key' => $other->key]))
            ->assertNotFound();
    }

    public function test_disabled_endpoint_returns_404(): void
    {
        $this->setUpContext();
        $this->endpoint->update(['is_active' => false]);

        $this->withApiKey()->postJson('/api/v1/deliveries', $this->request())
            ->assertNotFound();
    }

    public function test_delivery_is_accepted_and_duplicate_is_idempotent(): void
    {
        $this->setUpContext();
        Queue::fake();

        $first = $this->withApiKey()->postJson('/api/v1/deliveries', $this->request())
            ->assertAccepted()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('created', true);

        $second = $this->withApiKey()->postJson('/api/v1/deliveries', $this->request())
            ->assertAccepted()
            ->assertJsonPath('created', false);

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseCount('deliveries', 1);
        Queue::assertPushed(DeliverNotification::class, 1);
    }

    public function test_same_idempotency_key_with_different_content_conflicts(): void
    {
        $this->setUpContext();
        Queue::fake();
        $this->withApiKey()->postJson('/api/v1/deliveries', $this->request())->assertAccepted();

        $this->withApiKey()->postJson('/api/v1/deliveries', $this->request(['payload' => ['sku' => 'other']]))
            ->assertConflict();
    }

    public function test_payload_modes_and_dynamic_header_policy_are_enforced(): void
    {
        $this->setUpContext();
        Queue::fake();

        $this->withApiKey()->postJson('/api/v1/deliveries', $this->request([
            'payload' => null,
            'body_base64' => base64_encode('<stock>1</stock>'),
            'content_type' => 'application/xml',
        ]))->assertUnprocessable();

        $binary = $this->request([
            'body_base64' => base64_encode("\x00\xff"),
            'content_type' => 'application/octet-stream',
            'headers' => ['X-Correlation-ID' => 'trace-1'],
        ]);
        unset($binary['payload']);

        $response = $this->withApiKey()->postJson('/api/v1/deliveries', $binary)->assertAccepted();
        $this->assertSame("\x00\xff", Delivery::findOrFail($response->json('id'))->body);

        $this->withApiKey()->postJson('/api/v1/deliveries', $this->request([
            'idempotency_key' => 'order-43',
            'headers' => ['Authorization' => 'attacker'],
        ]))->assertUnprocessable();
    }

    public function test_only_owner_can_query_and_failed_delivery_can_be_retried(): void
    {
        $this->setUpContext();
        Queue::fake();
        $delivery = Delivery::query()->create([
            'api_client_id' => $this->client->id,
            'endpoint_id' => $this->endpoint->id,
            'idempotency_key' => 'failed-1',
            'request_hash' => str_repeat('a', 64),
            'content_type' => 'application/json',
            'body' => '{}',
            'dynamic_headers' => [],
            'status' => DeliveryStatus::Failed,
            'failed_at' => now(),
        ]);

        $this->withApiKey()->getJson('/api/v1/deliveries/'.$delivery->id)
            ->assertOk()->assertJsonMissing(['body' => '{}']);

        $this->withApiKey()->postJson('/api/v1/deliveries/'.$delivery->id.'/retry')
            ->assertAccepted()->assertJsonPath('status', 'pending');

        $this->assertSame(2, $delivery->refresh()->delivery_round);
        Queue::assertPushed(DeliverNotification::class, fn ($job) => $job->deliveryRound === 2);
    }

    public function test_cross_client_query_returns_404(): void
    {
        $this->setUpContext();
        Queue::fake([DeliverNotification::class]);
        $delivery = $this->withApiKey()->postJson('/api/v1/deliveries', $this->request())->json('id');
        $otherKey = 'other-key';
        ApiClient::query()->create([
            'name' => 'other',
            'key_hash' => hash('sha256', $otherKey),
            'is_active' => true,
        ]);

        $this->withHeader('X-API-Key', $otherKey)
            ->getJson('/api/v1/deliveries/'.$delivery)
            ->assertNotFound();
    }

    public function test_oversized_body_returns_422_without_dispatching(): void
    {
        $this->setUpContext();
        config(['notifications.max_body_bytes' => 4]);
        Queue::fake([DeliverNotification::class]);

        $this->withApiKey()->postJson('/api/v1/deliveries', $this->request(['payload' => ['long' => 'value']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payload');
        Queue::assertNothingPushed();
    }

    public function test_header_value_with_newline_returns_422(): void
    {
        $this->setUpContext();
        Queue::fake([DeliverNotification::class]);

        $this->withApiKey()->postJson('/api/v1/deliveries', $this->request([
            'headers' => ['X-Correlation-ID' => "safe\r\nInjected: value"],
        ]))->assertUnprocessable()->assertJsonValidationErrors('headers.X-Correlation-ID');
        Queue::assertNothingPushed();
    }

    private function withApiKey(): self
    {
        return $this->withHeader('X-API-Key', $this->apiKey);
    }

    private function request(array $overrides = []): array
    {
        return array_replace([
            'endpoint_key' => $this->endpoint->key,
            'idempotency_key' => 'order-42-inventory-v1',
            'payload' => ['sku' => 'A-1', 'delta' => -1],
            'content_type' => 'application/json',
            'headers' => ['X-Correlation-ID' => 'trace-42'],
        ], $overrides);
    }
}
