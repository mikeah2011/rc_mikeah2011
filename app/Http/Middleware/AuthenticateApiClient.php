<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-API-Key');

        if (! is_string($key) || $key === '') {
            return $this->unauthorized();
        }

        $client = ApiClient::query()
            ->where('key_hash', hash('sha256', $key))
            ->where('is_active', true)
            ->first();

        if (! $client) {
            return $this->unauthorized();
        }

        $request->attributes->set('api_client', $client);

        if ($client->last_used_at === null || $client->last_used_at->lt(now()->subMinutes(5))) {
            $client->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
