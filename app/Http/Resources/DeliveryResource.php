<?php

namespace App\Http\Resources;

use App\Models\Delivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Delivery */
class DeliveryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'attempts_count' => $this->attempts_count,
            'last_http_status' => $this->last_http_status,
            'last_error' => $this->last_error,
            'next_attempt_at' => $this->next_attempt_at?->toAtomString(),
            'delivered_at' => $this->delivered_at?->toAtomString(),
            'failed_at' => $this->failed_at?->toAtomString(),
            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
