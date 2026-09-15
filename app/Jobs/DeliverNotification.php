<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use Carbon\Carbon;

class DeliverNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $notificationId;

    public function __construct(string $notificationId)
    {
        $this->notificationId = $notificationId;
    }

    public function handle(): void
    {
        $notification = Notification::find($this->notificationId);
        if (! $notification) {
            Log::warning("DeliverNotification: notification not found {$this->notificationId}");
            return;
        }

        $attempt = NotificationAttempt::create([
            'notification_id' => $notification->id,
            'attempt_number' => $notification->attempts + 1,
            'started_at' => Carbon::now(),
        ]);

        $timeout = config('notifications.http_timeout', 15);

        try {
            $response = Http::timeout($timeout)
                ->withHeaders($notification->headers ?? [])
                ->send($notification->method, $notification->url, ['body' => $notification->body]);

            $attempt->http_status = $response->status();
            $attempt->finished_at = Carbon::now();
            $attempt->duration_ms = $attempt->finished_at->diffInMilliseconds($attempt->started_at);
            $attempt->save();

            if ($response->successful()) {
                $notification->status = 'delivered';
                $notification->attempts = $notification->attempts + 1;
                $notification->last_attempt_at = Carbon::now();
                $notification->save();
                return;
            }

            // Retryable: 408, 429, and 5xx
            if (in_array($response->status(), [408, 429]) || $response->serverError()) {
                $notification->attempts = $notification->attempts + 1;
                $notification->last_attempt_at = Carbon::now();
                $notification->next_attempt_at = Carbon::now()->addSeconds(60); // simple backoff
                $notification->save();
                return;
            }

            // Other 4xx -> permanent failure
            $notification->status = 'failed';
            $notification->attempts = $notification->attempts + 1;
            $notification->last_attempt_at = Carbon::now();
            $notification->save();
            return;

        } catch (\Exception $e) {
            $attempt->error = $e->getMessage();
            $attempt->finished_at = Carbon::now();
            $attempt->duration_ms = $attempt->finished_at->diffInMilliseconds($attempt->started_at);
            $attempt->save();

            $notification->attempts = $notification->attempts + 1;
            $notification->last_attempt_at = Carbon::now();
            $notification->next_attempt_at = Carbon::now()->addSeconds(60);
            $notification->save();
            Log::error("DeliverNotification exception for {$this->notificationId}: " . $e->getMessage());
            return;
        }
    }
}
