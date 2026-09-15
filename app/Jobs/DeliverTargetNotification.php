<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\NotificationTarget;
use App\Channels\ChannelManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DeliverTargetNotification implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public string $notificationId;
    public ?string $targetId;

    public function __construct(string $notificationId, ?string $targetId = null)
    {
        $this->notificationId = $notificationId;
        $this->targetId = $targetId;

        // ensure afterCommit for transaction safety
        if (property_exists($this, 'afterCommit')) {
            $this->afterCommit = true;
        }
    }

    public function handle(ChannelManager $manager)
    {
        // load fresh models
        $notification = Notification::find($this->notificationId);
        if (! $notification) {
            return;
        }

        $target = null;
        if ($this->targetId) {
            $target = NotificationTarget::find($this->targetId);
        }

        $channel = $target?->channel ?? $notification->channel ?? 'http';

        try {
            $driver = $manager->driver($channel);
            $driver->send($notification, $target);
        } catch (Throwable $e) {
            // Logging handled by channel/driver; allow job retry
            report($e);
            throw $e;
        }
    }
}
