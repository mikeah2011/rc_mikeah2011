<?php

namespace App\Models;

use Database\Factories\DeliveryAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryAttempt extends Model
{
    /** @use HasFactory<DeliveryAttemptFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'delivery_id', 'delivery_round', 'attempt_number', 'http_status', 'outcome',
        'duration_ms', 'error', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
}
