<?php

namespace App\Http\Controllers;

use App\Exceptions\IdempotencyConflict;
use App\Http\Requests\StoreDeliveryRequest;
use App\Http\Resources\DeliveryResource;
use App\Models\ApiClient;
use App\Models\Delivery;
use App\Services\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryController extends Controller
{
    public function __construct(private readonly DeliveryService $deliveries) {}

    public function store(StoreDeliveryRequest $request): JsonResponse
    {
        try {
            [$delivery, $created] = $this->deliveries->create($this->client($request), $request->validated());
        } catch (IdempotencyConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            ...(new DeliveryResource($delivery))->resolve(),
            'created' => $created,
        ], 202);
    }

    public function show(Request $request, Delivery $delivery): JsonResponse
    {
        abort_unless($delivery->api_client_id === $this->client($request)->id, 404);

        return (new DeliveryResource($delivery))->response();
    }

    public function retry(Request $request, Delivery $delivery): JsonResponse
    {
        $delivery = $this->deliveries->retry($this->client($request), $delivery);

        return (new DeliveryResource($delivery))->response()->setStatusCode(202);
    }

    private function client(Request $request): ApiClient
    {
        return $request->attributes->get('api_client');
    }
}
