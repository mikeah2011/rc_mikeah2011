<?php

namespace App\Services;

use App\Jobs\DeliverNotification;
use App\Repositories\NotificationRepository;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    protected NotificationRepository $repo;

    public function __construct(NotificationRepository $repo)
    {
        $this->repo = $repo;
    }

    public function createNotification(array $data, ?string $clientId, ?string $idempotencyKey)
    {
        return DB::transaction(function () use ($data, $clientId, $idempotencyKey) {
            if ($idempotencyKey) {
                $existing = $this->repo->findByClientAndIdempotency($clientId, $idempotencyKey);
                if ($existing) {
                    return ['status' => 'exists', 'notification' => $existing];
                }
            }

            $payload = [
                'id' => (string) Str::uuid(),
                'client_id' => $clientId,
                'idempotency_key' => $idempotencyKey,
                'method' => strtoupper($data['method']),
                'url' => $data['url'],
                'headers' => $data['headers'] ?? null,
                'body' => $data['body'] ?? null,
                'status' => 'pending',
                'delivery_round' => 1,
            ];

            // Only set channel if the column exists (keeps compatibility with fresh test DBs)
            if (\Illuminate\Support\Facades\Schema::hasColumn('notifications', 'channel')) {
                $payload['channel'] = $data['channel'] ?? 'http';
            }

            $notification = $this->repo->create($payload);

            // If outbox transactional dispatch is enabled, write an outbox row instead of dispatching directly.
            if (config('notifications.use_outbox', true)) {
                // create outbox entry; target_id null for legacy single-target path
                \App\Models\Outbox::create([
                    'notification_id' => $notification->id,
                    'target_id' => null,
                    'payload' => [
                        'action' => 'deliver_notification',
                        'notification_id' => $notification->id,
                    ],
                    'available_at' => now(),
                ]);

                // Note: outbox:flush should be run (cron/supervisor) or called via scheduler/worker
            } else {
                DeliverNotification::dispatch($notification->id);
            }

            return ['status' => 'created', 'notification' => $notification];
        });
    }

    public function retryNotification(string $id)
    {
        return DB::transaction(function () use ($id) {
            $notification = $this->repo->findByIdForUpdate($id);
            if (! $notification) {
                return ['status' => 'not_found'];
            }

            if ($notification->status !== 'failed') {
                return ['status' => 'invalid_state', 'notification' => $notification];
            }

            $notification->delivery_round = ($notification->delivery_round ?? 1) + 1;
            $notification->attempts = 0;
            $notification->status = 'pending';
            $notification->next_attempt_at = null;
            $notification->last_attempt_at = null;

            $this->repo->save($notification);

            DeliverNotification::dispatch($notification->id);

            return ['status' => 'accepted', 'notification' => $notification];
        });
    }
}
