<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateNotificationRequest;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;

class NotificationController
{
    protected NotificationService $service;

    public function __construct(NotificationService $service)
    {
        $this->service = $service;
    }

    public function store(CreateNotificationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $clientId = $request->header('X-Client-Id') ?? ($data['client_id'] ?? null);
        $idempotencyKey = $request->header('Idempotency-Key') ?? ($data['idempotency_key'] ?? null);

        $result = $this->service->createNotification($data, $clientId, $idempotencyKey);

        if ($result['status'] === 'exists') {
            $n = $result['notification'];
            return response()->json(['id' => $n->id, 'status' => $n->status], 200);
        }

        $n = $result['notification'];
        return response()->json(['id' => $n->id, 'status' => 'accepted'], 202);
    }

    public function retry(string $id): JsonResponse
    {
        $result = $this->service->retryNotification($id);

        if ($result['status'] === 'not_found') {
            return response()->json(['error' => 'notification not found'], 404);
        }

        if ($result['status'] === 'invalid_state') {
            return response()->json(['error' => 'only failed notifications can be retried'], 409);
        }

        $n = $result['notification'];
        return response()->json(['id' => $n->id, 'status' => 'accepted', 'delivery_round' => $n->delivery_round], 202);
    }
}
