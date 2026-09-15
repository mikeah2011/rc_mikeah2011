<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Models\ApiClient;
use App\Models\Delivery;
use App\Models\Endpoint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PruneDeliveriesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_retention_windows_are_applied(): void
    {
        $client = ApiClient::query()->create(['name' => 'prune', 'key_hash' => str_repeat('c', 64)]);
        $endpoint = Endpoint::query()->create([
            'key' => 'prune', 'vendor' => 'Vendor', 'url' => 'https://vendor.test',
            'static_headers' => [], 'allowed_dynamic_headers' => [], 'backoff_seconds' => [1],
        ]);

        foreach ([
            ['old-success', DeliveryStatus::Delivered, now()->subDays(31), null],
            ['new-success', DeliveryStatus::Delivered, now()->subDays(29), null],
            ['old-failed', DeliveryStatus::Failed, null, now()->subDays(91)],
            ['new-failed', DeliveryStatus::Failed, null, now()->subDays(89)],
        ] as [$key, $status, $deliveredAt, $failedAt]) {
            Delivery::query()->create([
                'api_client_id' => $client->id, 'endpoint_id' => $endpoint->id,
                'idempotency_key' => $key, 'request_hash' => hash('sha256', $key),
                'content_type' => 'application/json', 'body' => '{}', 'dynamic_headers' => [],
                'status' => $status, 'delivered_at' => $deliveredAt, 'failed_at' => $failedAt,
            ]);
        }

        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertDatabaseMissing('deliveries', ['idempotency_key' => 'old-success']);
        $this->assertDatabaseMissing('deliveries', ['idempotency_key' => 'old-failed']);
        $this->assertDatabaseHas('deliveries', ['idempotency_key' => 'new-success']);
        $this->assertDatabaseHas('deliveries', ['idempotency_key' => 'new-failed']);
    }
}
