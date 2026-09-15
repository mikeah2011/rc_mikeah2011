<?php

namespace Database\Factories;

use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryAttempt>
 */
class DeliveryAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'delivery_id' => Delivery::factory(),
            'delivery_round' => 1,
            'attempt_number' => 1,
            'outcome' => 'processing',
            'started_at' => now(),
        ];
    }
}
