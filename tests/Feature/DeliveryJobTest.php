<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Jobs\DeliverNotification;
use App\Models\ApiClient;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Endpoint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeliveryJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Delivery $delivery;

    private function setUpContext(): void
    {
        $client = ApiClient::query()->create(['name' => 'orders', 'key_hash' => str_repeat('a', 64)]);
        $endpoint = Endpoint::query()->create([
            'key' => 'inventory', 'vendor' => 'Inventory', 'url' => 'https://vendor.test/hook',
            'method' => 'POST', 'static_headers' => ['Authorization' => 'Bearer secret'],
            'allowed_dynamic_headers' => ['x-trace'], 'timeout_seconds' => 10,
            'max_attempts' => 3, 'backoff_seconds' => [1, 2, 3],
        ]);
        $this->delivery = Delivery::query()->create([
            'api_client_id' => $client->id, 'endpoint_id' => $endpoint->id,
            'idempotency_key' => 'idem-1', 'request_hash' => str_repeat('b', 64),
            'content_type' => 'application/json', 'body' => '{"hello":"world"}',
            'dynamic_headers' => ['x-trace' => 'trace-1'], 'status' => DeliveryStatus::Pending,
        ]);
    }

    public function test_success_is_recorded_and_headers_are_merged(): void
    {
        $this->setUpContext();
        Http::preventStrayRequests();
        Http::fake(['vendor.test/*' => Http::response('', 204)]);

        (new DeliverNotification($this->delivery->id, 1))->handle();

        $delivery = $this->delivery->refresh();
        $this->assertSame(DeliveryStatus::Delivered, $delivery->status);
        $this->assertSame(204, $delivery->last_http_status);
        $this->assertSame('delivered', $delivery->attempts()->first()->outcome);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer secret')
            && $request->hasHeader('Idempotency-Key', 'idem-1')
            && $request->body() === '{"hello":"world"}'
        );
    }

    public function test_retryable_response_is_released_and_permanent_response_fails(): void
    {
        $this->setUpContext();
        Http::preventStrayRequests();
        Http::fake(['vendor.test/*' => Http::sequence()
            ->push('', 503)
            ->push('', 400)]);
        $retryingJob = (new DeliverNotification($this->delivery->id, 1))->withFakeQueueInteractions();
        $retryingJob->handle();

        $this->assertSame(DeliveryStatus::Pending, $this->delivery->refresh()->status);
        $this->assertNotNull($this->delivery->next_attempt_at);
        $retryingJob->assertReleased();

        $failingJob = (new DeliverNotification($this->delivery->id, 1))->withFakeQueueInteractions();
        $failingJob->handle();

        $this->assertSame(DeliveryStatus::Failed, $this->delivery->refresh()->status);
        $this->assertSame(2, $this->delivery->attempts_count);
        $failingJob->assertFailed();
    }

    public function test_max_attempts_ends_in_failure(): void
    {
        $this->setUpContext();
        Http::preventStrayRequests();
        $this->delivery->endpoint->update(['max_attempts' => 1]);
        Http::fake(['vendor.test/*' => Http::response('', 429)]);

        $job = (new DeliverNotification($this->delivery->id, 1))->withFakeQueueInteractions();
        $job->handle();

        $this->assertSame(DeliveryStatus::Failed, $this->delivery->refresh()->status);
        $this->assertSame('failed', $this->delivery->attempts()->first()->outcome);
        $job->assertFailed();
    }

    public function test_terminal_or_stale_jobs_do_not_call_vendor(): void
    {
        $this->setUpContext();
        Http::preventStrayRequests();
        $this->delivery->update(['status' => DeliveryStatus::Delivered, 'delivered_at' => now()]);
        Http::fake();

        (new DeliverNotification($this->delivery->id, 1))->handle();
        (new DeliverNotification($this->delivery->id, 0))->handle();

        Http::assertNothingSent();
    }

    public function test_connection_failure_is_scheduled_for_retry(): void
    {
        $this->setUpContext();
        Http::preventStrayRequests();
        Http::fake(['vendor.test/*' => Http::failedConnection()]);

        $job = (new DeliverNotification($this->delivery->id, 1))->withFakeQueueInteractions();
        $job->handle();

        $this->assertSame(DeliveryStatus::Pending, $this->delivery->refresh()->status);
        $this->assertSame('retrying', $this->delivery->attempts()->first()->outcome);
        $job->assertReleased();
    }

    public function test_redirect_is_a_permanent_failure(): void
    {
        $this->setUpContext();
        Http::preventStrayRequests();
        Http::fake(['vendor.test/*' => Http::response('', 302, ['Location' => 'https://other.test'])]);

        $job = (new DeliverNotification($this->delivery->id, 1))->withFakeQueueInteractions();
        $job->handle();

        $this->assertSame(DeliveryStatus::Failed, $this->delivery->refresh()->status);
        $this->assertSame(302, $this->delivery->last_http_status);
        $job->assertFailed();
    }

    public function test_interrupted_attempt_is_marked_abandoned_when_job_recovers(): void
    {
        $this->setUpContext();
        DeliveryAttempt::query()->create([
            'delivery_id' => $this->delivery->id,
            'delivery_round' => 1,
            'attempt_number' => 1,
            'outcome' => 'processing',
            'started_at' => now()->subMinute(),
        ]);
        $this->delivery->update(['status' => DeliveryStatus::Processing, 'attempts_count' => 1]);
        Http::preventStrayRequests();
        Http::fake(['vendor.test/*' => Http::response('', 204)]);

        (new DeliverNotification($this->delivery->id, 1))->handle();

        $outcomes = $this->delivery->attempts()->orderBy('attempt_number')->pluck('outcome')->all();
        $this->assertSame(['abandoned', 'delivered'], $outcomes);
    }
}
