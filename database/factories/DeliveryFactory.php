<?php

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Models\ApiClient;
use App\Models\Delivery;
use App\Models\Endpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Delivery>
 */
class DeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = (string) Str::uuid();

        return [
            'api_client_id' => ApiClient::factory(),
            'endpoint_id' => Endpoint::factory(),
            'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key),
            'content_type' => 'application/json',
            'body' => '{}',
            'dynamic_headers' => [],
            'status' => DeliveryStatus::Pending,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Failed,
            'failed_at' => now(),
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Delivered,
            'delivered_at' => now(),
        ]);
    }
}
