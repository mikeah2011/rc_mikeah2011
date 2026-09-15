<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Notification extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'client_id', 'idempotency_key', 'method', 'url', 'headers', 'body', 'status', 'attempts', 'next_attempt_at', 'delivery_round'
    ];

    protected $casts = [
        'headers' => 'array',
        'next_attempt_at' => 'datetime',
        'delivery_round' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function attempts()
    {
        return $this->hasMany(NotificationAttempt::class);
    }
}
