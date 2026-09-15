<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/readyz', function () {
    try {
        DB::select('select 1');

        return response()->json(['status' => 'ready']);
    } catch (Throwable) {
        return response()->json(['status' => 'not ready'], 503);
    }
});
