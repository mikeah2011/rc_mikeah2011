<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string ;

    public function __construct(string )
    {
        ->notificationId = ;
    }

    public function handle(): void
    {
        // Delivery logic will be implemented in a later batch.
        // This stub exists so workers and queue wiring can be added incrementally.
    }
}
