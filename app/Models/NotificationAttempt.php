<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationAttempt extends Model
{
    protected $fillable = ['notification_id', 'attempt_number', 'started_at', 'finished_at', 'http_status', 'error', 'duration_ms', 'channel'];

    public function notification()
    {
        return $this->belongsTo(Notification::class);
    }
}
