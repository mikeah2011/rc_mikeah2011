<?php

namespace App\Channels;

use App\Models\Notification;
use App\Models\NotificationAttempt;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class HttpChannel implements ChannelContract
{
    public function send(Notification $notification): void
    {
        if ($notification->status !== 'pending') {
            return;
        }

        $maxAttempts = max(1, (int) config('notifications.max_attempts', 8));
        if ($notification->attempts >= $maxAttempts) {
            $notification->status = 'failed';
            $notification->next_attempt_at = null;
            $notification->save();

            return;
        }

        $startedAt = now();
        $started = hrtime(true);
        $attemptNumber = $notification->attempts + 1;
        $response = null;
        $error = null;

        try {
            $response = Http::timeout((int) config('notifications.http_timeout', 15))
                ->connectTimeout((int) config('notifications.connect_timeout', 5))
                ->withoutRedirecting()
                ->withHeaders($notification->headers ?? [])
                ->send($notification->method, $notification->url, ['body' => $notification->body ?? '']);
        } catch (ConnectionException $exception) {
            $error = $exception->getMessage();
        }

        $finishedAt = now();
        NotificationAttempt::create([
            'notification_id' => $notification->id,
            'attempt_number' => $attemptNumber,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'http_status' => $response?->status(),
            'error' => $error,
        ]);

        $retryable = $response === null || in_array($response->status(), [408, 429], true) || $response->serverError();
        $notification->attempts = $attemptNumber;
        $notification->last_attempt_at = $finishedAt;
        $notification->next_attempt_at = null;
        if ($response?->successful()) {
            $notification->status = 'delivered';
        } elseif ($retryable && $attemptNumber < $maxAttempts) {
            $notification->next_attempt_at = $finishedAt->copy()->addSeconds($this->retryDelay($response, $attemptNumber));
        } else {
            $notification->status = 'failed';
        }
        $notification->save();
    }

    private function retryDelay(?Response $response, int $attemptNumber): int
    {
        $max = max(0, (int) config('notifications.max_backoff_seconds', 3600));
        $retryAfter = $response?->header('Retry-After');
        if ($retryAfter !== null && $retryAfter !== '') {
            $raw = trim($retryAfter);
            if (ctype_digit($raw)) {
                return min($max, (int) $raw);
            }
            $timestamp = $this->httpDateTimestamp($raw);
            if ($timestamp !== null) {
                return min($max, max(0, $timestamp - now()->timestamp));
            }
        }

        $base = max(0, (int) config('notifications.base_backoff_seconds', 10));
        $jitter = max(0, (int) config('notifications.backoff_jitter_seconds', 5));

        return (int) min($max, $base * (2 ** min($attemptNumber - 1, 30)) + random_int(0, $jitter));
    }

    private function httpDateTimestamp(string $raw): ?int
    {
        $format = 'D, d M Y H:i:s \G\M\T';
        if (preg_match('/^([A-Z][a-z]+, \d{2}-[A-Z][a-z]{2}-)(\d{2})( \d{2}:\d{2}:\d{2} GMT)$/D', $raw, $parts)) {
            // RFC 850 dates use the most recent matching year no more than 50 years ahead.
            $year = intdiv(now()->year, 100) * 100 + (int) $parts[2];
            if ($year > now()->year + 50) {
                $year -= 100;
            }
            $raw = $parts[1].$year.$parts[3];
            $format = 'l, d-M-Y H:i:s \G\M\T';
        } elseif (preg_match('/^[A-Z][a-z]{2} [A-Z][a-z]{2} (?: [1-9]|[12]\d|3[01]) \d{2}:\d{2}:\d{2} \d{4}$/D', $raw)) {
            $raw = preg_replace('/^([A-Z][a-z]{2} [A-Z][a-z]{2})  ([1-9]) /', '$1 0$2 ', $raw);
            $format = 'D M d H:i:s Y';
        }

        $date = DateTimeImmutable::createFromFormat('!'.$format, $raw, new DateTimeZone('GMT'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $date->format($format) !== $raw) {
            return null;
        }

        return $date->getTimestamp();
    }
}
