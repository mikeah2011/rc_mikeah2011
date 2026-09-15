<?php

namespace App\Console\Commands;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use Illuminate\Console\Command;

class PruneDeliveries extends Command
{
    protected $signature = 'notifications:prune';

    protected $description = 'Delete expired delivered and failed notification records';

    public function handle(): int
    {
        $delivered = $this->prune(
            DeliveryStatus::Delivered,
            'delivered_at',
            now()->subDays(config('notifications.success_retention_days')),
        );
        $failed = $this->prune(
            DeliveryStatus::Failed,
            'failed_at',
            now()->subDays(config('notifications.failure_retention_days')),
        );

        $this->info("Pruned {$delivered} delivered and {$failed} failed records.");

        return self::SUCCESS;
    }

    private function prune(DeliveryStatus $status, string $dateColumn, mixed $cutoff): int
    {
        $deleted = 0;

        do {
            $ids = Delivery::query()
                ->where('status', $status)
                ->where($dateColumn, '<', $cutoff)
                ->limit(500)
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                $deleted += Delivery::query()->whereKey($ids)->delete();
            }
        } while ($ids->count() === 500);

        return $deleted;
    }
}
