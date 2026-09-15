<?php

namespace App\Channels;

use App\Models\Notification;

interface ChannelContract
{
    /**
     * Send a single notification (or target) via this channel.
     * Implementations should handle attempt recording, retry decisions, and status updates.
     *
     * @param Notification $notification
     * @return void
     */
    public function send(Notification $notification): void;
}
