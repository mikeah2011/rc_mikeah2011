<?php

namespace App\Console\Commands;

use App\Jobs\DeliverTargetNotification;
use App\Models\Outbox;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class FlushOutbox extends Command
{
    protected $signature = 'outbox:flush {--limit= : Maximum rows to publish (1-10000)}';

    protected $description = 'Publish due outbox entries to the persistent notification queue';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $limit = filter_var($this->option('limit') ?? config('notifications.outbox_flush_limit', 100), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 10000],
        ]);
        if ($limit === false) {
            $this->error('The outbox limit must be an integer between 1 and 10000.');

            return self::FAILURE;
        }
        $connection = $dispatcher->connection();
        $cutoff = now();
        $ids = Outbox::whereNull('processed_at')
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', $cutoff))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $published = 0;
        $invalid = 0;
        foreach ($ids as $id) {
            $result = DB::transaction(function () use ($id, $cutoff, $connection): ?int {
                $row = Outbox::whereKey($id)->lockForUpdate()->first();
                if (! $row || $row->processed_at || ($row->available_at && $row->available_at->gt($cutoff))) {
                    return 0;
                }

                $payload = $row->payload ?? [];
                $round = is_array($payload) && array_key_exists('delivery_round', $payload) ? $payload['delivery_round'] : 1;
                if (! is_array($payload) || ! is_int($round) || $round < 1 || $row->target_id !== null
                    || $row->notification_id === ''
                    || (isset($payload['notification_id']) && $payload['notification_id'] !== $row->notification_id)
                    || (isset($payload['action']) && $payload['action'] !== 'deliver_notification')) {
                    Log::error('Invalid or unsupported outbox payload', ['outbox_id' => $row->id]);

                    $this->error("Invalid or unsupported outbox row {$row->id}: left unprocessed; repair its payload/target before retrying.");

                    return null;
                }

                // Publish first, explicitly bypass after_commit even when the queue enables it.
                // External brokers can publish twice if this transaction's commit fails.
                Queue::connection($connection)->push(
                    (new DeliverTargetNotification($row->notification_id, null, $round))->beforeCommit()
                );
                $row->processed_at = now();
                $row->save();

                return 1;
            });
            if ($result === null) {
                $invalid++;
            } else {
                $published += $result;
            }
        }

        $this->info("Flushed {$published} outbox rows");

        return $invalid > 0 ? self::FAILURE : self::SUCCESS;
    }
}
