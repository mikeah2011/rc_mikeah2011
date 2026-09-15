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
     * (Queueable trait declares the property; we set it in constructor to avoid composition conflicts)
     */
    public $afterCommit;

    public string $notificationId;

    public function __construct(string $notificationId)
    {
        $this->afterCommit = true;
        $this->notificationId = $notificationId;
    }

    public function handle(): void
    {
        $notification = Notification::find($this->notificationId);
        if (! $notification) {
            Log::warning("DeliverNotification: notification not found {$this->notificationId}");
            return;
        }

        // determine channel (default to 'http')
        $channelName = $notification->channel ?? 'http';
        $manager = app(\App\Channels\ChannelManager::class);

        try {
            $channel = $manager->driver($channelName);
            $channel->send($notification);

            // If channel indicates a retry is needed by setting next_attempt_at and attempts,
            // re-release job with delay here if configured (calculate delay from notification->next_attempt_at)
            if ($notification->next_attempt_at && $notification->next_attempt_at instanceof \Carbon\Carbon) {
                $delay = max(0, $notification->next_attempt_at->getTimestamp() - time());
                if ($delay > 0) {
                    $this->release($delay);
                }
            }

            return;
        } catch (\Exception $e) {
            Log::error("DeliverNotification: channel [{$channelName}] failed for {$this->notificationId}: " . $e->getMessage());
            return;
        }
    }
}
