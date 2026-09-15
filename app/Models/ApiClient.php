<?php

namespace App\Models;

use Database\Factories\ApiClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApiClient extends Model
{
    /** @use HasFactory<ApiClientFactory> */
    use HasFactory;

    protected $fillable = ['name', 'key_hash', 'is_active', 'last_used_at'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_used_at' => 'datetime'];
    }

    public function endpoints(): BelongsToMany
    {
        return $this->belongsToMany(Endpoint::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }
}
