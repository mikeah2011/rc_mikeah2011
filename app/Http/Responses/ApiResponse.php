<?php

namespace App\Http\Responses;

use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ApiResponse
{
    public static function success($data = null, array $meta = [], int $status = HttpResponse::HTTP_OK)
    {
        $payload = ['data' => $data];
        if (! empty($meta)) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    public static function ok($data = null, array $meta = [])
    {
        return self::success($data, $meta, HttpResponse::HTTP_OK);
    }

    public static function accepted($data = null, array $meta = [])
    {
        return self::success($data, $meta, HttpResponse::HTTP_ACCEPTED);
    }

    public static function created($data = null, array $meta = [])
    {
        return self::success($data, $meta, HttpResponse::HTTP_CREATED);
    }

    public static function error(string $message, int $code = 0, array $details = [], int $status = HttpResponse::HTTP_BAD_REQUEST)
    {
        $payload = ['error' => ['code' => $code, 'message' => $message]];
        if (! empty($details)) {
            $payload['error']['details'] = $details;
        }

        return response()->json($payload, $status);
    }

    public static function notFound(string $message = 'Not Found', int $code = 0)
    {
        return self::error($message, $code, [], HttpResponse::HTTP_NOT_FOUND);
    }

    public static function conflict(string $message = 'Conflict', int $code = 0)
    {
        return self::error($message, $code, [], HttpResponse::HTTP_CONFLICT);
    }
}
