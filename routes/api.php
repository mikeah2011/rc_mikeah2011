<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NotificationController;

Route::post('/notifications', [NotificationController::class, 'store']);
Route::post('/notifications/{id}/retry', [NotificationController::class, 'retry']);
