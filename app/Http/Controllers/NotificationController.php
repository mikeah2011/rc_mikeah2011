<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateNotificationRequest;
use App\Jobs\DeliverNotification;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;

class NotificationController
{
    public function store(CreateNotificationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $clientId = $request->header('X-Client-Id') ?? ($data['client_id'] ?? null);
        $idempotencyKey = $request->header('Idempotency-Key') ?? ($data['idempotency_key'] ?? null);

        return DB::transaction(function () use ($data, $clientId, $idempotencyKey) {
            if ($idempotencyKey) {
                $existing = Notification::where('client_id', $clientId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return response()->json(['id' => $existing->id, 'status' => $existing->status], 200);
                }
            }

            $notification = Notification::create([
                'id' => (string) Str::uuid(),
                'client_id' => $clientId,
                'idempotency_key' => $idempotencyKey,
                'method' => strtoupper($data['method']),
                'url' => $data['url'],
                'headers' => $data['headers'] ?? null,
                'body' => $data['body'] ?? null,
                'status' => 'pending',
                'delivery_round' => 1,
            ]);

            DeliverNotification::dispatch($notification->id);

            return response()->json(['id' => $notification->id, 'status' => 'accepted'], 202);
        });
    }

    public function retry(string $id): JsonResponse
    {
        return DB::transaction(function () use ($id) {
            $notification = Notification::where('id', $id)->lockForUpdate()->first();
            if (! $notification) {
                return response()->json(['error' => 'notification not found'], 404);
            }

            if ($notification->status !== 'failed') {
                return response()->json(['error' => 'only failed notifications can be retried'], 409);
            }

            // Start a new delivery round
            $notification->delivery_round = ($notification->delivery_round ?? 1) + 1;
            $notification->attempts = 0;
            $notification->status = 'pending';
            $notification->next_attempt_at = null;
            $notification->last_attempt_at = null;
            $notification->save();

            DeliverNotification::dispatch($notification->id);

            return response()->json(['id' => $notification->id, 'status' => 'accepted', 'delivery_round' => $notification->delivery_round], 202);
        });
    }
}
