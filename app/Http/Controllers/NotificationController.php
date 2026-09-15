<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateNotificationRequest;
use App\Jobs\DeliverNotification;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationController
{
    public function store(CreateNotificationRequest )
    {
         = ->validated();
         = ->header('X-Client-Id') ?? (['client_id'] ?? null);
         = ->header('Idempotency-Key') ?? (['idempotency_key'] ?? null);

        return DB::transaction(function () use (, , ) {
            if () {
                 = Notification::where('client_id', )
                    ->where('idempotency_key', )
                    ->first();
                if () {
                    return response()->json(['id' => ->id, 'status' => ->status], 200);
                }
            }

             = Notification::create([
                'id' => (string) Str::uuid(),
                'client_id' => ,
                'idempotency_key' => ,
                'method' => strtoupper(['method']),
                'url' => ['url'],
                'headers' => ['headers'] ?? null,
                'body' => ['body'] ?? null,
                'status' => 'pending',
            ]);

            DeliverNotification::dispatch(->id);

            return response()->json(['id' => ->id, 'status' => 'accepted'], 202);
        });
    }
}
