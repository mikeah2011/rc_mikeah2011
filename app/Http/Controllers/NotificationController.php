<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateNotificationRequest;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use App\Http\Resources\NotificationResource;
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
            return ApiResponse::success(new NotificationResource($n), [], 200);
        }

        $n = $result['notification'];
        return ApiResponse::success(new NotificationResource($n), [], 202);
    }

    public function retry(string $id): JsonResponse
    {
        $result = $this->service->retryNotification($id);

        if ($result['status'] === 'not_found') {
            return ApiResponse::error('notification not found', 404, [], 404);
        }

        if ($result['status'] === 'invalid_state') {
            return ApiResponse::error('only failed notifications can be retried', 409, [], 409);
        }

        $n = $result['notification'];
        return ApiResponse::success(new NotificationResource($n), [], 202);
    }
}
