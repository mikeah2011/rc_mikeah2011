<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Notification;

class RetryNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_retry_failed_notification()
    {
        // create a failed notification
        $notification = Notification::create([
            'id' => (string) '\\Illuminate\\Support\\Str'::uuid(),
            'method' => 'POST',
            'url' => 'https://example.com/webhook',
            'status' => 'failed',
            'attempts' => 3,
            'delivery_round' => 1,
        ]);

        $response = $this->postJson("/api/notifications/{$notification->id}/retry");

        $response->assertStatus(202);
        $response->assertJsonPath('id', $notification->id);
        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => 'pending',
        ]);
    }
}
