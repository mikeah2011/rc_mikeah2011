<?php

namespace App\Jobs;

use App\Channels\ChannelManager;
use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliverNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $notificationId;

    // A missing property in an old serialized job must never inherit the current round.
    public int $deliveryRound = 1;

    public int $tries = 100;

    public int $timeout = 30;

    public int $backoff = 10;

    public bool $failOnTimeout = true;

    public function __construct(string $notificationId, int $deliveryRound = 1)
    {
        $this->notificationId = $notificationId;
        $this->deliveryRound = $deliveryRound;
        $this->tries = (int) config('notifications.queue_tries', 100);
        $this->timeout = (int) config('notifications.job_timeout', 30);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('notification:'.$this->notificationId))
                ->shared()
                ->releaseAfter(5)
                ->expireAfter((int) config('notifications.lock_seconds', 60)),
        ];
    }

    public function handle(): void
    {
        if (($this->deliveryRound ?? 1) < 1) {
            Log::error('Invalid notification delivery round', ['notification_id' => $this->notificationId]);

            throw new \InvalidArgumentException('Delivery round must be positive.');
        }

        // One row, one bounded HTTP attempt. Never retry this transaction automatically:
        // a remote server may have accepted a request even if our commit fails.
        $delay = DB::transaction(function (): ?int {
            $notification = Notification::whereKey($this->notificationId)->lockForUpdate()->first();
            if (! $notification) {
                Log::warning('Delivery notification not found', ['notification_id' => $this->notificationId]);

                return null;
            }

            if ($notification->delivery_round !== ($this->deliveryRound ?? 1) || $notification->status !== 'pending') {
                return null;
            }

            if ($notification->next_attempt_at?->isFuture()) {
                return max(0, $notification->next_attempt_at->timestamp - now()->timestamp);
            }

            app(ChannelManager::class)->driver($notification->channel ?? 'http')->send($notification);

            return $notification->status === 'pending' && $notification->next_attempt_at
                ? max(0, $notification->next_attempt_at->timestamp - now()->timestamp)
                : null;
        });

        if ($delay !== null) {
            $this->release($delay);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $notification = Notification::whereKey($this->notificationId)->lockForUpdate()->first();
            if (! $notification) {
                Log::warning('Failed delivery notification not found', ['notification_id' => $this->notificationId]);

                return;
            }

            if ($notification->delivery_round !== ($this->deliveryRound ?? 1) || $notification->status !== 'pending') {
                return;
            }

            $notification->status = 'failed';
            $notification->next_attempt_at = null;
            $notification->save();
            Log::error('Notification queue job failed', [
                'notification_id' => $this->notificationId,
                'delivery_round' => $this->deliveryRound ?? 1,
                'error' => $exception?->getMessage(),
            ]);
        });
    }
}
