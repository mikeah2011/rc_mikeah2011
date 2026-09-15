<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Notification extends Model
{
    public  = false;
    protected  = 'string';

    protected  = [
        'id', 'client_id', 'idempotency_key', 'method', 'url', 'headers', 'body', 'status', 'attempts', 'next_attempt_at'
    ];

    protected  = [
        'headers' => 'array',
        'next_attempt_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function () {
            if (empty(->id)) {
                ->id = (string) Str::uuid();
            }
        });
    }

    public function attempts()
    {
        return ->hasMany(NotificationAttempt::class);
    }
}
