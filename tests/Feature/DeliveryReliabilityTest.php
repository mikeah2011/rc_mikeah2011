<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotification;
use App\Jobs\DeliverTargetNotification;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use App\Models\Outbox;
use App\Services\NotificationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliveryReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'queue.default' => 'database',
            'queue.connections.database.after_commit' => true,
            'cache.default' => 'database',
            'notifications.use_outbox' => true,
            'notifications.max_attempts' => 2,
            'notifications.base_backoff_seconds' => 10,
            'notifications.backoff_jitter_seconds' => 0,
        ]);
        Http::preventStrayRequests();
    }

    private function accept(array $overrides = []): Notification
    {
        $response = $this->postJson('/api/notifications', array_merge([
            'url' => 'https://example.com/hook',
            'method' => 'POST',
            'headers' => ['Content-Type' => 'text/plain'],
            'body' => " \n raw content \t ",
        ], $overrides))->assertAccepted();

        return Notification::findOrFail($response->json('data.id'));
    }

    private function work(): void
    {
        app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(
            sleep: 0, maxTries: 100, force: true,
        ));
    }

    public function test_real_outbox_queue_worker_delivers_raw_request_once(): void
    {
        Http::fake(['*' => Http::response('', 204)]);
        $notification = $this->accept();
        $this->assertDatabaseCount('jobs', 0);
        $this->artisan('outbox:flush')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $queued = unserialize(json_decode(DB::table('jobs')->value('payload'), true)['data']['command']);
        $this->assertSame(1, $queued->deliveryRound);
        $this->assertFalse($queued->afterCommit);
        $this->artisan('outbox:flush')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $this->work();
        $this->assertSame('delivered', $notification->fresh()->status);
        $this->assertDatabaseCount('notification_attempts', 1);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertSent(fn ($request) => $request->body() === " \n raw content \t "
            && $request->method() === 'POST'
            && $request->hasHeader('Content-Type', 'text/plain')
            && $request->toPsrRequest()->getUri()->__toString() === $notification->url);
    }

    public function test_enqueue_failure_does_not_mark_outbox_processed(): void
    {
        $this->accept();
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('queue unavailable'));
        try {
            $this->artisan('outbox:flush')->run();
            $this->fail('Expected enqueue failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('queue unavailable', $exception->getMessage());
        }
        $this->assertNull(Outbox::first()->processed_at);
    }

    public function test_database_publish_and_mark_roll_back_together_on_storage_failure(): void
    {
        $this->accept();
        Outbox::updating(fn () => throw new \RuntimeException('storage failure'));
        try {
            $this->artisan('outbox:flush')->run();
            $this->fail('Expected storage failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('storage failure', $exception->getMessage());
        } finally {
            Outbox::flushEventListeners();
        }
        $this->assertNull(Outbox::first()->processed_at);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_external_publish_before_mark_can_duplicate_after_commit_failure(): void
    {
        config(['queue.default' => 'redis']);
        $this->accept();
        $published = [];
        $publisher = \Mockery::mock();
        $publisher->shouldReceive('push')->twice()->andReturnUsing(function ($job) use (&$published) {
            $this->assertFalse($job->afterCommit);
            $this->assertNull(Outbox::first()->processed_at);
            $published[] = $job;

            return count($published);
        });
        Queue::shouldReceive('connection')->with('redis')->andReturn($publisher);
        Outbox::updating(fn () => throw new \RuntimeException('commit failed'));
        try {
            $this->artisan('outbox:flush')->run();
            $this->fail('Expected persistence failure');
        } catch (\RuntimeException) {
            $this->assertCount(1, $published);
        } finally {
            Outbox::flushEventListeners();
        }
        $this->artisan('outbox:flush')->assertSuccessful();
        $this->assertCount(2, $published);
        $this->assertSame($published[0]->notificationId, $published[1]->notificationId);
        $this->assertSame($published[0]->deliveryRound, $published[1]->deliveryRound);
    }

    public function test_outbox_insert_failure_rolls_back_acceptance(): void
    {
        Outbox::creating(fn () => throw new \RuntimeException('outbox unavailable'));
        try {
            app(NotificationService::class)->createNotification([
                'url' => 'https://example.com/hook', 'method' => 'POST',
            ], null, null);
            $this->fail('Expected outbox failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('outbox unavailable', $exception->getMessage());
        } finally {
            Outbox::flushEventListeners();
        }
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('outbox', 0);
    }

    public function test_retry_after_is_persisted_released_and_exhausted_on_final_attempt(): void
    {
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '30'])]);
        $notification = $this->accept();
        $this->artisan('outbox:flush')->assertSuccessful();
        $this->work();
        $this->assertSame('pending', $notification->fresh()->status);
        $this->assertEqualsWithDelta(now()->addSeconds(30)->timestamp, $notification->fresh()->next_attempt_at->timestamp, 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->work();
        Http::assertSentCount(1);
        $this->travel(31)->seconds();
        $this->work();
        $this->assertSame('failed', $notification->fresh()->status);
        $this->assertNull($notification->fresh()->next_attempt_at);
        $this->assertDatabaseCount('notification_attempts', 2);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_connection_error_retries_and_zero_delay_is_released(): void
    {
        config(['notifications.base_backoff_seconds' => 0]);
        Http::fake(['*' => Http::failedConnection()]);
        $notification = $this->accept();
        $this->artisan('outbox:flush')->assertSuccessful();
        $this->work();
        $this->assertSame('pending', $notification->fresh()->status);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertNotEmpty(NotificationAttempt::first()->error);
        $this->work();
        $this->assertSame('failed', $notification->fresh()->status);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_permanent_failure_and_redirects_are_not_retried_or_followed(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertFalse($options['allow_redirects']);

            return Http::response('', (int) basename($request->url()), ['Location' => 'https://example.com/other']);
        });
        foreach ([400, 401, 404, 302] as $status) {
            $notification = $this->accept(['url' => "https://example.com/{$status}"]);
            $this->artisan('outbox:flush')->assertSuccessful();
            $this->work();
            $this->assertSame('failed', $notification->fresh()->status);
            $this->assertSame(1, $notification->fresh()->attempts);
            $this->assertNull($notification->fresh()->next_attempt_at);
            $this->assertSame($status, NotificationAttempt::where('notification_id', $notification->id)->first()->http_status);
        }
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_future_due_duplicate_stale_and_terminal_jobs_do_not_send(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $notification = $this->accept();
        $notification->update(['next_attempt_at' => now()->addMinute()]);
        $this->artisan('outbox:flush')->assertSuccessful();
        $this->work();
        Http::assertNothingSent();
        $this->assertDatabaseCount('notification_attempts', 0);
        $this->travel(61)->seconds();
        $this->work();
        Queue::connection()->push((new DeliverNotification($notification->id, 1))->beforeCommit());
        $this->work();
        $notification->refresh()->update(['status' => 'pending', 'delivery_round' => 2, 'attempts' => 0]);
        Queue::connection()->push((new DeliverNotification($notification->id, 1))->beforeCommit());
        $this->work();
        Http::assertSentCount(1);
        $this->assertSame('pending', $notification->fresh()->status);
    }

    public function test_manual_retry_uses_outbox_with_new_round(): void
    {
        $notification = $this->accept();
        $notification->update(['status' => 'failed']);
        $this->postJson("/api/notifications/{$notification->id}/retry")->assertAccepted();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('outbox', 2);
        $this->assertSame(2, Outbox::where('payload->delivery_round', 2)->firstOrFail()->payload['delivery_round']);
        Http::fake(['*' => Http::response('', 200)]);
        $this->artisan('outbox:flush')->assertSuccessful();
        $this->work();
        $this->work();
        Http::assertSentCount(1);
        $this->assertSame('delivered', $notification->fresh()->status);
    }

    public function test_manual_retry_delivers_identical_raw_content(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 400)->push('', 200)]);
        $notification = $this->accept();
        $this->artisan('outbox:flush')->assertSuccessful();
        $this->work();
        $this->postJson("/api/notifications/{$notification->id}/retry")->assertAccepted();
        $this->artisan('outbox:flush')->assertSuccessful();
        $this->work();
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame(" \n raw content \t ", $request->body());
        }
        $this->assertSame('delivered', $notification->fresh()->status);
    }

    public function test_legacy_payload_defaults_to_initial_round(): void
    {
        $notification = $this->accept();
        $notification->update(['delivery_round' => 2]);
        Outbox::first()->update(['payload' => ['notification_id' => $notification->id]]);
        $this->artisan('outbox:flush')->assertSuccessful();
        $this->work();
        Http::assertNothingSent();
        $legacy = new DeliverTargetNotification($notification->id);
        unset($legacy->deliveryRound);
        Queue::connection()->push($legacy->beforeCommit());
        $this->work();
        Http::assertNothingSent();
    }

    public function test_delivery_lock_releases_competing_job_without_sending(): void
    {
        $notification = $this->accept();
        $this->artisan('outbox:flush')->assertSuccessful();
        $lock = Cache::lock('laravel-queue-overlap:notification:'.$notification->id, 60);
        $this->assertTrue($lock->get());
        try {
            $this->work();
            Http::assertNothingSent();
            $this->assertDatabaseCount('jobs', 1);
        } finally {
            $lock->release();
        }
    }

    public function test_failed_hook_only_marks_current_pending_round(): void
    {
        $notification = $this->accept();
        $job = new DeliverNotification($notification->id, 1);
        $notification->update(['delivery_round' => 2]);
        $job->failed(new \RuntimeException('worker exhausted'));
        $this->assertSame('pending', $notification->fresh()->status);
        $notification->update(['delivery_round' => 1, 'status' => 'delivered']);
        $job->failed(new \RuntimeException('worker exhausted'));
        $this->assertSame('delivered', $notification->fresh()->status);
        $notification->update(['status' => 'pending', 'next_attempt_at' => now()->addMinute()]);
        $job->failed(new \RuntimeException('worker exhausted'));
        $this->assertSame('failed', $notification->fresh()->status);
        $this->assertNull($notification->fresh()->next_attempt_at);
    }

    public function test_storage_errors_propagate_and_roll_back_attempt_results(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $notification = $this->accept();
        Notification::updating(fn () => throw new \RuntimeException('storage failure'));
        try {
            (new DeliverNotification($notification->id, 1))->handle();
            $this->fail('Expected storage failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('storage failure', $exception->getMessage());
        } finally {
            Notification::flushEventListeners();
        }
        $this->assertDatabaseCount('notification_attempts', 0);
        $this->assertSame('pending', $notification->fresh()->status);
    }

    public function test_unsupported_target_never_sends_parent_url(): void
    {
        $notification = $this->accept();
        $this->expectException(\InvalidArgumentException::class);
        try {
            (new DeliverTargetNotification($notification->id, 'target-id', 1))->handle();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_flush_limit_default_validation_and_schedule_registration(): void
    {
        config(['notifications.outbox_flush_limit' => 1]);
        $this->accept();
        $this->accept();
        $this->artisan('outbox:flush')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        foreach (['0', '-1', 'oops', '10001'] as $limit) {
            $this->artisan('outbox:flush', ['--limit' => $limit])->assertFailed();
        }
        $events = app(Schedule::class)->events();
        $this->assertTrue(collect($events)->contains(fn ($event) => str_contains($event->command ?? '', 'outbox:flush')
            && $event->expression === '* * * * *'));
    }

    public function test_flush_skips_future_rows_and_rechecks_each_locked_row(): void
    {
        $this->accept();
        $this->accept();
        $future = $this->accept();
        Outbox::where('notification_id', $future->id)->update(['available_at' => now()->addHour()]);
        $rows = Outbox::where('notification_id', '!=', $future->id)->orderBy('id')->get();
        $publisher = \Mockery::mock();
        $publisher->shouldReceive('push')->once()->andReturnUsing(function ($job) use ($rows) {
            $this->assertSame($rows[0]->notification_id, $job->notificationId);
            $rows[1]->update(['processed_at' => now()]);

            return 'published';
        });
        Queue::shouldReceive('connection')->with('database')->andReturn($publisher);
        $this->artisan('outbox:flush')->assertSuccessful();
        $this->assertNull(Outbox::where('notification_id', $future->id)->first()->processed_at);
        $this->assertSame(2, Outbox::whereNotNull('processed_at')->count());
    }

    public function test_unsafe_outbox_queues_are_rejected_before_acceptance(): void
    {
        foreach (['sync', 'null', 'deferred', 'background', 'failover'] as $driver) {
            config(['queue.default' => 'unsafe', 'queue.connections.unsafe.driver' => $driver]);
            try {
                app(NotificationService::class)->createNotification([
                    'url' => 'https://example.com/hook', 'method' => 'POST',
                ], null, null);
                $this->fail("Accepted unsafe queue {$driver}");
            } catch (\LogicException) {
                $this->assertDatabaseCount('notifications', 0);
                $this->assertDatabaseCount('outbox', 0);
            }
        }
    }

    public function test_direct_database_queue_is_immediate_and_rolls_back_with_notification(): void
    {
        config(['notifications.use_outbox' => false]);
        DB::beginTransaction();
        try {
            $this->accept();
            $this->assertDatabaseCount('jobs', 1);
            $this->assertDatabaseCount('outbox', 0);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_direct_external_or_different_database_queue_is_rejected(): void
    {
        config(['notifications.use_outbox' => false]);
        foreach ([
            ['driver' => 'redis'],
            ['driver' => 'database', 'connection' => 'other'],
        ] as $connection) {
            config(['queue.default' => 'unsafe', 'queue.connections.unsafe' => $connection]);
            try {
                app(NotificationService::class)->createNotification([
                    'url' => 'https://example.com/hook', 'method' => 'POST',
                ], null, null);
                $this->fail('Unsafe direct queue accepted');
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('same database connection', $exception->getMessage());
            }
            $this->assertDatabaseCount('notifications', 0);
        }
    }

    public function test_idempotency_key_conflicts_for_different_content(): void
    {
        $payload = ['url' => 'https://example.com/hook', 'method' => 'POST', 'body' => 'first'];
        $headers = ['Idempotency-Key' => 'request-key', 'X-Client-Id' => 'client'];
        $first = $this->postJson('/api/notifications', $payload, $headers)->assertAccepted();
        $this->postJson('/api/notifications', $payload, $headers)->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson('/api/notifications', array_merge($payload, ['body' => ' first ']), $headers)
            ->assertConflict();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('outbox', 1);
    }

    public function test_retryable_statuses_use_bounded_retry_after_and_backoff(): void
    {
        config(['notifications.max_backoff_seconds' => 60]);
        $this->freezeTime();
        $cases = [
            408 => [null, 10],
            500 => ['invalid', 10],
            502 => ['99999', 60],
            503 => [now()->addSeconds(40)->toRfc7231String(), 40],
            504 => ['0', 0],
        ];
        Http::fake(fn ($request) => Http::response('', (int) basename($request->url()), array_filter([
            'Retry-After' => $cases[(int) basename($request->url())][0],
        ], fn ($value) => $value !== null)));
        foreach ($cases as $status => [$header, $delay]) {
            $notification = $this->accept(['url' => "https://example.com/{$status}"]);
            (new DeliverNotification($notification->id, 1))->handle();
            $this->assertSame('pending', $notification->fresh()->status);
            $this->assertSame(now()->addSeconds($delay)->timestamp, $notification->fresh()->next_attempt_at->timestamp);
        }
    }

    public function test_empty_and_whitespace_only_bodies_are_preserved(): void
    {
        foreach (['', " \t\n "] as $body) {
            $notification = $this->accept(['body' => $body]);
            $this->assertSame($body, $notification->body);
        }
        $this->postJson('/api/notifications', ['url' => 'ftp://example.com/file', 'method' => 'POST'])->assertUnprocessable();
    }

    public function test_retry_after_rejects_non_http_dates_and_bounds_overflow(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 6)->setTime(12, 0));
        config(['notifications.max_backoff_seconds' => 60]);
        $cases = [
            ['-1', 10],
            ['tomorrow', 10],
            ['+30 seconds', 10],
            ['999999999999999999999999999999999999999999999', 60],
            ['Sun, 06 Sep 2026 12:00:40 GMT', 40],
            ['Sunday, 06-Sep-26 12:00:40 GMT', 40],
            ['Sun Sep  6 12:00:40 2026', 40],
            ['Sun, 06 Sep 2026 12:00:99 GMT', 10],
            ['Sun, 31 Feb 2026 12:00:40 GMT', 10],
            ['Mon, 06 Sep 2026 12:00:40 GMT', 10],
            ['Sun, 06 Sep 2026 12:00:40 UTC', 10],
            ['Sun, 06 Sep 2026 11:59:59 GMT', 0],
        ];
        Http::fake(fn ($request) => Http::response('', 503, [
            'Retry-After' => $cases[(int) basename($request->url())][0],
        ]));
        foreach ($cases as $index => [$header, $delay]) {
            $notification = $this->accept(['url' => "https://example.com/{$index}"]);
            (new DeliverNotification($notification->id, 1))->handle();
            $this->assertSame(now()->addSeconds($delay)->timestamp, $notification->fresh()->next_attempt_at->timestamp, $header);
        }
    }

    public function test_missing_notification_and_invalid_outbox_payload_are_safe(): void
    {
        $notification = $this->accept();
        $notification->delete();
        $this->artisan('outbox:flush')->assertSuccessful();
        $this->work();
        Http::assertNothingSent();
        $this->assertDatabaseCount('jobs', 0);

        $other = $this->accept();
        $row = Outbox::where('notification_id', $other->id)->firstOrFail();
        $row->update(['payload' => ['delivery_round' => 'invalid']]);
        $this->artisan('outbox:flush')->expectsOutputToContain($row->id)->assertFailed();
        $this->assertNull($row->fresh()->processed_at);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_invalid_outbox_rows_do_not_block_valid_selected_rows(): void
    {
        $invalid = $this->accept();
        $targeted = $this->accept();
        $valid = $this->accept();
        $invalidRow = Outbox::where('notification_id', $invalid->id)->firstOrFail();
        $targetRow = Outbox::where('notification_id', $targeted->id)->firstOrFail();
        $invalidRow->update(['payload' => ['delivery_round' => 'invalid']]);
        $targetRow->update(['target_id' => (string) Str::uuid()]);
        $this->assertSame($invalidRow->id, Outbox::orderBy('id')->first()->id);
        Http::fake(['*' => Http::response('', 204)]);
        $this->artisan('outbox:flush')
            ->expectsOutputToContain($invalidRow->id)
            ->expectsOutputToContain($targetRow->id)
            ->assertFailed();
        $this->assertNull($invalidRow->fresh()->processed_at);
        $this->assertNull($targetRow->fresh()->processed_at);
        $this->assertDatabaseCount('jobs', 1);
        $this->work();
        $this->assertSame('delivered', $valid->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_queue_exhaustion_marks_pending_round_failed(): void
    {
        Http::fake(['*' => Http::response('', 503)]);
        config(['notifications.queue_tries' => 1, 'notifications.base_backoff_seconds' => 0]);
        $notification = $this->accept();
        $this->artisan('outbox:flush')->assertSuccessful();
        $this->work();
        $this->work();
        $this->assertSame('failed', $notification->fresh()->status);
        $this->assertNull($notification->fresh()->next_attempt_at);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertSentCount(1);
    }

    public function test_unsafe_timeout_ordering_is_rejected(): void
    {
        config(['notifications.http_timeout' => 30]);
        $this->expectException(\LogicException::class);
        app(NotificationService::class)->createNotification([
            'url' => 'https://example.com/hook', 'method' => 'POST',
        ], null, null);
    }
}
