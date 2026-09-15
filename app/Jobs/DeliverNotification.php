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

    /**
     * Ensure job is dispatched only after DB transaction commits
     */
    public $afterCommit = true;

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

        // Refresh protected config values
        $timeout = config('notifications.http_timeout', 15);
        $maxAttempts = config('notifications.max_attempts', 8);
        $baseBackoff = config('notifications.base_backoff_seconds', 10);
        $maxBackoff = config('notifications.max_backoff_seconds', 3600);
        $jitter = config('notifications.backoff_jitter_seconds', 5);

        // If we've already exhausted attempts, mark failed and stop
        if ($notification->attempts >= $maxAttempts) {
            $notification->status = 'failed';
            $notification->save();
            Log::warning("DeliverNotification: max attempts reached for {$this->notificationId}");
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

            // Determine retryability and delay
            $status = $response->status();
            $isServerError = $response->serverError();
            $isRetryable = in_array($status, [408, 429]) || $isServerError;

            if ($isRetryable) {
                // Respect Retry-After header if present
                $retryAfter = null;
                if ($response->hasHeader('Retry-After')) {
                    $raw = $response->header('Retry-After');
                    // numeric seconds
                    if (is_numeric($raw)) {
                        $retryAfter = (int) $raw;
                    } else {
                        // try parse HTTP-date
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
                    // exponential backoff with jitter
                    $exp = $baseBackoff * (2 ** ($attemptNumber - 1));
                    $delay = min($exp + random_int(0, $jitter), $maxBackoff);
                }

                // update notification record and re-release job for retry
                $notification->attempts = $attemptNumber;
                $notification->last_attempt_at = Carbon::now();
                $notification->next_attempt_at = Carbon::now()->addSeconds($delay);
                $notification->save();

                // release the job back to the queue with delay
                $this->release($delay);
                return;
            }

            // Non-retryable (other 4xx)
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

            // exponential backoff with jitter for exceptions
            $exp = $baseBackoff * (2 ** ($attemptNumber - 1));
            $delay = min($exp + random_int(0, $jitter), $maxBackoff);
            $notification->next_attempt_at = Carbon::now()->addSeconds($delay);
            $notification->save();

            Log::error("DeliverNotification exception for {$this->notificationId}: " . $e->getMessage());

            // re-release job with delay unless max attempts reached
            if ($notification->attempts < $maxAttempts) {
                $this->release($delay);
            } else {
                $notification->status = 'failed';
                $notification->save();
            }

            return;
        }
    }
}
