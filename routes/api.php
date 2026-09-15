<?php

use App\Http\Controllers\DeliveryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api.client', 'throttle:deliveries'])->prefix('v1')->group(function (): void {
    Route::post('/deliveries', [DeliveryController::class, 'store']);
    Route::get('/deliveries/{delivery}', [DeliveryController::class, 'show']);
    Route::post('/deliveries/{delivery}/retry', [DeliveryController::class, 'retry']);
});
