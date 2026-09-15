<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class NotificationTarget extends Model
{
    protected $fillable = ['notification_id', 'channel', 'target'];

    public function notification()
    {
        return $this->belongsTo(Notification::class);
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }
}
