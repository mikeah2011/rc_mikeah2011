<?php

namespace App\Models;

use Database\Factories\EndpointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Endpoint extends Model
{
    /** @use HasFactory<EndpointFactory> */
    use HasFactory;

    protected $fillable = [
        'key', 'vendor', 'url', 'method', 'static_headers', 'allowed_dynamic_headers',
        'timeout_seconds', 'max_attempts', 'backoff_seconds', 'is_active',
    ];

    protected $hidden = ['static_headers'];

    protected function casts(): array
    {
        return [
            'static_headers' => 'encrypted:array',
            'allowed_dynamic_headers' => 'array',
            'backoff_seconds' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(ApiClient::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }
}
