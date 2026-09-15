<?php

namespace App\Services;

use App\Jobs\DeliverNotification;
use App\Models\Notification;
use App\Models\Outbox;
use Illuminate\Support\Facades\Queue;
use LogicException;

class NotificationDispatcher
{
    public function connection(bool $direct = false): string
    {
        $name = config('queue.default');
        $connection = config("queue.connections.{$name}", []);
        $driver = $connection['driver'] ?? null;
        if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw new LogicException('Notification delivery requires a persistent database, redis, sqs or beanstalkd queue.');
        }

        if ($direct && ($driver !== 'database'
            || ($connection['connection'] ?? config('database.default')) !== (new Notification)->getConnection()->getName())) {
            throw new LogicException('Direct delivery requires the database queue on the same database connection as notifications.');
        }

        $this->validateTimeouts($connection);

        return $name;
    }

    private function validateTimeouts(array $connection): void
    {
        $values = [];
        foreach (['connect_timeout', 'http_timeout', 'job_timeout', 'lock_seconds', 'queue_tries', 'max_attempts'] as $key) {
            $values[$key] = filter_var(config("notifications.{$key}"), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($values[$key] === false) {
                throw new LogicException("notifications.{$key} must be a positive integer.");
            }
        }
        if ($values['connect_timeout'] > $values['http_timeout']
            || $values['http_timeout'] >= $values['job_timeout']
            || $values['job_timeout'] >= $values['lock_seconds']
            || (isset($connection['retry_after']) && $values['lock_seconds'] >= (int) $connection['retry_after'])) {
            throw new LogicException('Require connect <= HTTP < job timeout < lock lifetime < queue retry_after.');
        }
    }

    public function schedule(Notification $notification): void
    {
        $outbox = config('notifications.use_outbox', true);
        $connection = $this->connection(! $outbox);
        if ($outbox) {
            Outbox::create([
                'notification_id' => $notification->id,
                'target_id' => null,
                'payload' => [
                    'action' => 'deliver_notification',
                    'notification_id' => $notification->id,
                    'delivery_round' => $notification->delivery_round,
                ],
                'available_at' => now(),
            ]);

            return;
        }

        Queue::connection($connection)->push(
            (new DeliverNotification($notification->id, $notification->delivery_round))->beforeCommit()
        );
    }
}
