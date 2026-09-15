<?php

namespace Database\Factories;

use App\Models\Endpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Endpoint>
 */
class EndpointFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => Str::slug(fake()->unique()->words(2, true)),
            'vendor' => fake()->company(),
            'url' => 'https://'.fake()->unique()->domainName().'/webhook',
            'method' => 'POST',
            'static_headers' => [],
            'allowed_dynamic_headers' => [],
            'timeout_seconds' => 10,
            'max_attempts' => 3,
            'backoff_seconds' => [5, 30, 120],
            'is_active' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
