<?php

namespace App\Support;

class ApiResponse
{
    public static function success($data = null, array $meta = [], int $status = 200)
    {
        $payload = ['data' => $data];
        if (! empty($meta)) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    public static function error(string $message, int $code = 0, array $details = [], int $status = 400)
    {
        $payload = ['error' => ['code' => $code, 'message' => $message]];
        if (! empty($details)) {
            $payload['error']['details'] = $details;
        }

        return response()->json($payload, $status);
    }
}
