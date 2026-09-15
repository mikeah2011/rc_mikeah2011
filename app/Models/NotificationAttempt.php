<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationAttempt extends Model
{
    protected  = ['notification_id', 'attempt_number', 'started_at', 'finished_at', 'http_status', 'error', 'duration_ms'];

    public function notification()
    {
        return ->belongsTo(Notification::class);
    }
}
