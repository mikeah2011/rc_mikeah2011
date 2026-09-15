<?php

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DeliverNotification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 100;

    public int $maxExceptions = 3;

    public int $timeout = 40;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $deliveryId,
        public readonly int $deliveryRound,
    ) {}

    public function handle(): void
    {
        [$delivery, $attempt] = $this->beginAttempt();

        if (! $delivery || ! $attempt) {
            return;
        }

        $started = hrtime(true);

        if (! $delivery->endpoint->is_active) {
            $this->finishFailure($delivery, $attempt, null, 'Endpoint is disabled.', false, $started);

            return;
        }

        try {
            $response = Http::withHeaders($this->headers($delivery))
                ->withBody($delivery->body, $delivery->content_type)
                ->connectTimeout(min(5, $delivery->endpoint->timeout_seconds))
                ->timeout($delivery->endpoint->timeout_seconds)
                ->withOptions(['allow_redirects' => false])
                ->send($delivery->endpoint->method, $delivery->endpoint->url);

            if ($response->successful()) {
                $this->finishSuccess($delivery, $attempt, $response, $started);

                return;
            }

            $retryable = in_array($response->status(), [408, 429], true) || $response->serverError();
            $this->finishFailure($delivery, $attempt, $response->status(), 'HTTP '.$response->status(), $retryable, $started);
        } catch (ConnectionException $exception) {
            $this->finishFailure($delivery, $attempt, null, $this->safeError($exception), true, $started);
        } catch (Throwable $exception) {
            $this->finishFailure($delivery, $attempt, null, $this->safeError($exception), true, $started);
        }
    }

    /** @return array{0: Delivery|null, 1: DeliveryAttempt|null} */
    private function beginAttempt(): array
    {
        return DB::transaction(function (): array {
            $delivery = Delivery::query()->with('endpoint')->lockForUpdate()->find($this->deliveryId);

            if (! $delivery || $delivery->delivery_round !== $this->deliveryRound) {
                return [null, null];
            }

            if (in_array($delivery->status, [DeliveryStatus::Delivered, DeliveryStatus::Failed], true)) {
                return [null, null];
            }

            $attemptNumber = $delivery->attempts_count + 1;
            DeliveryAttempt::query()
                ->where('delivery_id', $delivery->id)
                ->where('delivery_round', $delivery->delivery_round)
                ->where('outcome', 'processing')
                ->update([
                    'outcome' => 'abandoned',
                    'error' => 'Worker stopped before recording the result.',
                    'finished_at' => now(),
                ]);

            $delivery->forceFill([
                'status' => DeliveryStatus::Processing,
                'attempts_count' => $attemptNumber,
                'next_attempt_at' => null,
            ])->save();

            $attempt = DeliveryAttempt::query()->create([
                'delivery_id' => $delivery->id,
                'delivery_round' => $delivery->delivery_round,
                'attempt_number' => $attemptNumber,
                'outcome' => 'processing',
                'started_at' => now(),
            ]);

            return [$delivery, $attempt];
        }, 3);
    }

    private function finishSuccess(Delivery $delivery, DeliveryAttempt $attempt, Response $response, int $started): void
    {
        DB::transaction(function () use ($delivery, $attempt, $response, $started): void {
            $this->finishAttempt($attempt, 'delivered', $response->status(), null, $started);

            Delivery::query()
                ->whereKey($delivery->id)
                ->where('delivery_round', $this->deliveryRound)
                ->whereNotIn('status', [DeliveryStatus::Delivered->value, DeliveryStatus::Failed->value])
                ->update([
                    'status' => DeliveryStatus::Delivered->value,
                    'last_http_status' => $response->status(),
                    'last_error' => null,
                    'next_attempt_at' => null,
                    'delivered_at' => now(),
                    'failed_at' => null,
                    'updated_at' => now(),
                ]);
        }, 3);
    }

    private function finishFailure(
        Delivery $delivery,
        DeliveryAttempt $attempt,
        ?int $status,
        string $error,
        bool $retryable,
        int $started,
    ): void {
        $final = ! $retryable || $attempt->attempt_number >= $delivery->endpoint->max_attempts;
        $delay = $final ? null : $this->retryDelay($delivery, $attempt->attempt_number);

        DB::transaction(function () use ($delivery, $attempt, $status, $error, $final, $delay, $started): void {
            $this->finishAttempt($attempt, $final ? 'failed' : 'retrying', $status, $error, $started);

            Delivery::query()
                ->whereKey($delivery->id)
                ->where('delivery_round', $this->deliveryRound)
                ->whereNotIn('status', [DeliveryStatus::Delivered->value, DeliveryStatus::Failed->value])
                ->update([
                    'status' => ($final ? DeliveryStatus::Failed : DeliveryStatus::Pending)->value,
                    'last_http_status' => $status,
                    'last_error' => $error,
                    'next_attempt_at' => $delay === null ? null : now()->addSeconds($delay),
                    'failed_at' => $final ? now() : null,
                    'updated_at' => now(),
                ]);
        }, 3);

        if ($final) {
            $this->fail(new RuntimeException($error));
        } else {
            $this->release($delay);
        }
    }

    private function finishAttempt(
        DeliveryAttempt $attempt,
        string $outcome,
        ?int $status,
        ?string $error,
        int $started,
    ): void {
        $attempt->forceFill([
            'outcome' => $outcome,
            'http_status' => $status,
            'error' => $error,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'finished_at' => now(),
        ])->save();
    }

    private function retryDelay(Delivery $delivery, int $attempt): int
    {
        $schedule = $delivery->endpoint->backoff_seconds ?: [5, 30, 120, 600, 1800, 3600];
        $base = (int) $schedule[min($attempt - 1, count($schedule) - 1)];

        return max(1, (int) round($base * random_int(50, 150) / 100));
    }

    private function headers(Delivery $delivery): array
    {
        return array_merge(
            $delivery->endpoint->static_headers ?? [],
            $delivery->dynamic_headers ?? [],
            [
                'Content-Type' => $delivery->content_type,
                'Idempotency-Key' => $delivery->idempotency_key,
            ],
        );
    }

    private function safeError(Throwable $exception): string
    {
        return mb_substr(class_basename($exception).': '.$exception->getMessage(), 0, config('notifications.max_error_length'));
    }

    public function failed(?Throwable $exception): void
    {
        Delivery::query()
            ->whereKey($this->deliveryId)
            ->where('delivery_round', $this->deliveryRound)
            ->whereNotIn('status', [DeliveryStatus::Delivered->value, DeliveryStatus::Failed->value])
            ->update([
                'status' => DeliveryStatus::Failed->value,
                'last_error' => $exception ? $this->safeError($exception) : 'Queue job failed.',
                'next_attempt_at' => null,
                'failed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
