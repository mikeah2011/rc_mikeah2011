<?php

namespace Database\Factories;

use App\Models\ApiClient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiClient>
 */
class ApiClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'key_hash' => hash('sha256', Str::random(48)),
            'is_active' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
