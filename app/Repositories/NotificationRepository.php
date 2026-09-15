<?php

namespace App\Repositories;

use App\Models\Notification;

class NotificationRepository
{
    public function findByClientAndIdempotency(?string $clientId, ?string $idempotencyKey)
    {
        if (! $idempotencyKey) {
            return null;
        }

        return Notification::where('client_id', $clientId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function create(array $payload): Notification
    {
        return Notification::create($payload);
    }

    public function findByIdForUpdate(string $id): ?Notification
    {
        return Notification::where('id', $id)->lockForUpdate()->first();
    }

    public function save(Notification $notification): bool
    {
        return $notification->save();
    }
}
