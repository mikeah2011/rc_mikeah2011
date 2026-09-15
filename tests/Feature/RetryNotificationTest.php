<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RetryNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_retry_failed_notification()
    {
        config(['queue.default' => 'database', 'notifications.use_outbox' => true]);

        // create a failed notification
        $notification = Notification::create([
            'id' => (string) Str::uuid(),
            'method' => 'POST',
            'url' => 'https://example.com/webhook',
            'status' => 'failed',
            'attempts' => 3,
            'delivery_round' => 1,
        ]);

        $response = $this->postJson("/api/notifications/{$notification->id}/retry");

        $response->assertStatus(202);
        $response->assertJsonPath('data.id', $notification->id);
        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => 'pending',
        ]);

        $this->assertSame(2, Outbox::firstOrFail()->payload['delivery_round']);
        $this->assertDatabaseCount('jobs', 0);
    }
}
