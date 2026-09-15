<?php

namespace App\Channels;

use App\Models\Notification;
use App\Models\NotificationAttempt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class HttpChannel implements ChannelContract
{
    public function send(Notification $notification): void
    {
        // mirror previous DeliverNotification logic for HTTP
        $timeout = config('notifications.http_timeout', 15);
        $maxAttempts = config('notifications.max_attempts', 8);
        $baseBackoff = config('notifications.base_backoff_seconds', 10);
        $maxBackoff = config('notifications.max_backoff_seconds', 3600);
        $jitter = config('notifications.backoff_jitter_seconds', 5);

        if ($notification->attempts >= $maxAttempts) {
            $notification->status = 'failed';
            $notification->save();
            Log::warning("HttpChannel: max attempts reached for {$notification->id}");
            return;
        }

        $attemptNumber = $notification->attempts + 1;

        $attempt = NotificationAttempt::create([
            'notification_id' => $notification->id,
            'attempt_number' => $attemptNumber,
            'started_at' => Carbon::now(),
        ]);

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
                $notification->attempts = $attemptNumber;
                $notification->last_attempt_at = Carbon::now();
                $notification->next_attempt_at = null;
                $notification->save();
                return;
            }

            $status = $response->status();
            $isServerError = $response->serverError();
            $isRetryable = in_array($status, [408, 429]) || $isServerError;

            if ($isRetryable) {
                $retryAfter = null;
                if ($response->hasHeader('Retry-After')) {
                    $raw = $response->header('Retry-After');
                    if (is_numeric($raw)) {
                        $retryAfter = (int) $raw;
                    } else {
                        try {
                            $ts = strtotime($raw);
                            if ($ts !== false) {
                                $retryAfter = max(0, $ts - time());
                            }
                        } catch (\Throwable $e) {
                            $retryAfter = null;
                        }
                    }
                }

                if ($retryAfter !== null) {
                    $delay = min($retryAfter, $maxBackoff);
                } else {
                    $exp = $baseBackoff * (2 ** ($attemptNumber - 1));
                    $delay = min($exp + random_int(0, $jitter), $maxBackoff);
                }

                $notification->attempts = $attemptNumber;
                $notification->last_attempt_at = Carbon::now();
                $notification->next_attempt_at = Carbon::now()->addSeconds($delay);
                $notification->save();

                // Caller (job) should handle re-release / re-dispatch with delay
                return;
            }

            $notification->status = 'failed';
            $notification->attempts = $attemptNumber;
            $notification->last_attempt_at = Carbon::now();
            $notification->next_attempt_at = null;
            $notification->save();
            return;

        } catch (\Exception $e) {
            $attempt->error = $e->getMessage();
            $attempt->finished_at = Carbon::now();
            $attempt->duration_ms = $attempt->finished_at->diffInMilliseconds($attempt->started_at);
            $attempt->save();

            $attemptNumber = $notification->attempts + 1;
            $notification->attempts = $attemptNumber;
            $notification->last_attempt_at = Carbon::now();

            $exp = $baseBackoff * (2 ** ($attemptNumber - 1));
            $delay = min($exp + random_int(0, $jitter), $maxBackoff);
            $notification->next_attempt_at = Carbon::now()->addSeconds($delay);
            $notification->save();

            Log::error("HttpChannel exception for {$notification->id}: " . $e->getMessage());

            return;
        }
    }
}
