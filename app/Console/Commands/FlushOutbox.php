<?php

namespace App\Console\Commands;

use App\Jobs\DeliverTargetNotification;
use App\Models\Outbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FlushOutbox extends Command
{
    protected $signature = 'outbox:flush {--limit=100}';

    protected $description = 'Flush available outbox entries into queue as DeliverTargetNotification jobs';

    public function handle()
    {
        $limit = (int) $this->option('limit');

        $rows = Outbox::whereNull('processed_at')
            ->where(function ($q) {
                $q->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->orderBy('available_at')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No outbox rows to flush');
            return 0;
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as $r) {
                // mark processed_at to avoid double-queue
                $r->processed_at = now();
                $r->save();

                // dispatch per-target job
                DeliverTargetNotification::dispatch($r->notification_id, $r->target_id);
            }
        });

        $this->info('Flushed ' . $rows->count() . ' outbox rows');
        return 0;
    }
}
