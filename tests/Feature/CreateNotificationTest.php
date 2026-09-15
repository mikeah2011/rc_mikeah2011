<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_notification()
    {
        config(['queue.default' => 'database', 'notifications.use_outbox' => true]);
        $payload = [
            'url' => 'https://example.com/webhook',
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['hello' => 'world']),
        ];

        $this->postJson('/api/notifications', $payload)
            ->assertStatus(202)
            ->assertJsonStructure(['data' => ['id', 'status']]);
    }
}
