<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_notification()
    {
         = [
            'url' => 'https://example.com/webhook',
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['hello' => 'world']),
        ];

         = ->postJson('/api/notifications', );

        ->assertStatus(202);
        ->assertJsonStructure(['id', 'status']);
    }
}
