<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use Database\Factories\DeliveryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    /** @use HasFactory<DeliveryFactory> */
    use HasFactory, HasUuids;

    protected $attributes = [
        'status' => 'pending',
        'delivery_round' => 1,
        'attempts_count' => 0,
    ];

    protected $fillable = [
        'api_client_id', 'endpoint_id', 'idempotency_key', 'request_hash', 'content_type',
        'body', 'dynamic_headers', 'status', 'delivery_round', 'attempts_count',
        'last_http_status', 'last_error', 'next_attempt_at', 'delivered_at', 'failed_at',
    ];

    protected $hidden = ['body', 'dynamic_headers', 'request_hash'];

    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'dynamic_headers' => 'encrypted:array',
            'status' => DeliveryStatus::class,
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(Endpoint::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }
}
