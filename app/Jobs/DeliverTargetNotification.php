<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;

// Keep the old queue payload class readable during upgrades.
class DeliverTargetNotification extends DeliverNotification
{
    public ?string $targetId = null;

    public function __construct(string $notificationId, ?string $targetId = null, int $deliveryRound = 1)
    {
        parent::__construct($notificationId, $deliveryRound);
        $this->targetId = $targetId;
    }

    public function handle(): void
    {
        if ($this->targetId !== null) {
            Log::error('Non-null notification targets are unsupported', [
                'notification_id' => $this->notificationId,
                'target_id' => $this->targetId,
            ]);

            throw new \InvalidArgumentException('Only single-target HTTP notifications are supported.');
        }

        parent::handle();
    }
}
