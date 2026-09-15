<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| First small-batch: provide a minimal POST /api/notifications endpoint
| that accepts a JSON payload, performs light validation, and returns
| 202 Accepted with a generated notification id. This is a non-production
| placeholder to be extended in later batches.
|
*/

Route::post('/notifications', function (Request ) {
     = ->json()->all();

    // Minimal validation: require url and method
    if (empty(['url']) || empty(['method'])) {
        return response()->json(['error' => 'url and method are required'], 422);
    }

    // Generate an id for this notification (UUID v4)
     = (string) Str::uuid();

    // In later batches we will persist the notification and enqueue delivery.
    return response()->json(['id' => , 'status' => 'accepted'], 202);
});
