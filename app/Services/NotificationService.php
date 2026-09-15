<?php

namespace App\Services;

use App\Repositories\NotificationRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationService
{
    protected NotificationRepository $repo;

    public function __construct(NotificationRepository $repo, protected NotificationDispatcher $dispatcher)
    {
        $this->repo = $repo;
    }

    public function createNotification(array $data, ?string $clientId, ?string $idempotencyKey)
    {
        return DB::transaction(function () use ($data, $clientId, $idempotencyKey) {
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = $this->repo->findByClientAndIdempotency($clientId, $idempotencyKey);
                if ($existing) {
                    $existingHeaders = $existing->headers ?? [];
                    $headers = $data['headers'] ?? [];
                    ksort($existingHeaders);
                    ksort($headers);
                    $same = $existing->method === strtoupper($data['method'])
                        && $existing->url === $data['url']
                        && $existingHeaders === $headers
                        && $existing->body === ($data['body'] ?? null);

                    return ['status' => $same ? 'exists' : 'conflict', 'notification' => $existing];
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

            $notification = $this->repo->create($payload);

            $this->dispatcher->schedule($notification);

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

            $this->dispatcher->schedule($notification);

            return ['status' => 'accepted', 'notification' => $notification];
        });
    }
}
