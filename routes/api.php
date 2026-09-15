<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Models\Notification;
use App\Jobs\DeliverNotification;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Small-batch: persist notification and enqueue a delivery job within a DB
| transaction. Idempotency key (Idempotency-Key header or idempotency_key in
| payload) is respected at a basic level: if a matching (client_id, key)
| notification exists, return it instead of creating a duplicate.
|
*/

Route::post('/notifications', function (Request $request) {
    $data = $request->json()->all();
    $clientId = $request->header('X-Client-Id') ?? ($data['client_id'] ?? null);
    $idempotencyKey = $request->header('Idempotency-Key') ?? ($data['idempotency_key'] ?? null);

    // Minimal validation: require url and method
    if (empty($data['url']) || empty($data['method'])) {
        return response()->json(['error' => 'url and method are required'], 422);
    }

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
        ]);

        // Enqueue delivery job. Using database queue requires jobs table migration
        // which will be added in a later batch or by running `php artisan queue:table`.
        DeliverNotification::dispatch($notification->id);

        return response()->json(['id' => $notification->id, 'status' => 'accepted'], 202);
    });
});
