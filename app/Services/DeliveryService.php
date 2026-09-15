<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Exceptions\IdempotencyConflict;
use App\Jobs\DeliverNotification;
use App\Models\ApiClient;
use App\Models\Delivery;
use App\Models\Endpoint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryService
{
    private const FORBIDDEN_HEADERS = [
        'authorization', 'proxy-authorization', 'host', 'content-length', 'transfer-encoding',
        'connection', 'keep-alive', 'te', 'trailer', 'upgrade', 'idempotency-key',
    ];

    /** @return array{0: Delivery, 1: bool} */
    public function create(ApiClient $client, array $input): array
    {
        $endpoint = $client->endpoints()
            ->where('endpoints.key', $input['endpoint_key'])
            ->where('endpoints.is_active', true)
            ->firstOrFail();

        $headers = $this->validateHeaders($endpoint, $input['headers'] ?? []);
        $body = $this->bodyFromInput($input);
        $requestHash = $this->requestHash($endpoint, $input['content_type'], $headers, $body);

        try {
            return DB::transaction(function () use ($client, $endpoint, $input, $headers, $body, $requestHash): array {
                $existing = Delivery::query()
                    ->where('api_client_id', $client->id)
                    ->where('idempotency_key', $input['idempotency_key'])
                    ->first();

                if ($existing) {
                    return [$this->resolveExisting($existing, $requestHash), false];
                }

                $delivery = Delivery::query()->create([
                    'api_client_id' => $client->id,
                    'endpoint_id' => $endpoint->id,
                    'idempotency_key' => $input['idempotency_key'],
                    'request_hash' => $requestHash,
                    'content_type' => $input['content_type'],
                    'body' => $body,
                    'dynamic_headers' => $headers,
                    'status' => DeliveryStatus::Pending,
                    'delivery_round' => 1,
                    'attempts_count' => 0,
                    'next_attempt_at' => now(),
                ]);

                DeliverNotification::dispatch($delivery->id, $delivery->delivery_round)
                    ->onQueue(config('notifications.queue'));

                return [$delivery, true];
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = Delivery::query()
                ->where('api_client_id', $client->id)
                ->where('idempotency_key', $input['idempotency_key'])
                ->firstOrFail();

            return [$this->resolveExisting($existing, $requestHash), false];
        }
    }

    public function retry(ApiClient $client, Delivery $delivery): Delivery
    {
        return DB::transaction(function () use ($client, $delivery): Delivery {
            $locked = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);

            abort_unless($locked->api_client_id === $client->id, 404);

            if ($locked->status !== DeliveryStatus::Failed) {
                abort(409, 'Only failed deliveries may be retried.');
            }

            $locked->forceFill([
                'status' => DeliveryStatus::Pending,
                'delivery_round' => $locked->delivery_round + 1,
                'attempts_count' => 0,
                'last_http_status' => null,
                'last_error' => null,
                'next_attempt_at' => now(),
                'failed_at' => null,
                'delivered_at' => null,
            ])->save();

            DeliverNotification::dispatch($locked->id, $locked->delivery_round)
                ->onQueue(config('notifications.queue'));

            return $locked->refresh();
        }, 3);
    }

    private function bodyFromInput(array $input): string
    {
        if (array_key_exists('body_base64', $input)) {
            $body = base64_decode($input['body_base64'], true);
        } else {
            $body = json_encode($input['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($body === false || strlen($body) > config('notifications.max_body_bytes')) {
            throw ValidationException::withMessages(['payload' => 'The encoded body is too large.']);
        }

        return $body;
    }

    private function validateHeaders(Endpoint $endpoint, array $headers): array
    {
        $allowed = array_map('strtolower', $endpoint->allowed_dynamic_headers ?? []);
        $normalized = [];

        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);

            if (in_array($lower, self::FORBIDDEN_HEADERS, true) || ! in_array($lower, $allowed, true)) {
                throw ValidationException::withMessages(['headers.'.$name => 'This dynamic header is not allowed.']);
            }

            $normalized[$lower] = $value;
        }

        ksort($normalized);

        return $normalized;
    }

    private function requestHash(Endpoint $endpoint, string $contentType, array $headers, string $body): string
    {
        return hash('sha256', json_encode([
            'endpoint_id' => $endpoint->id,
            'content_type' => $contentType,
            'headers' => $headers,
            'body_base64' => base64_encode($body),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function resolveExisting(Delivery $delivery, string $requestHash): Delivery
    {
        if (! hash_equals($delivery->request_hash, $requestHash)) {
            throw new IdempotencyConflict('The idempotency key was already used with different content.');
        }

        return $delivery;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true);
    }
}
