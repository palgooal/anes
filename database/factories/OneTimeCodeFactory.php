<?php

namespace Database\Factories;

use App\CodePurpose;
use App\Models\OneTimeCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OneTimeCode>
 */
class OneTimeCodeFactory extends Factory
{
    /**
     * Define the model's default state. The plain code is "1234".
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->patient(),
            'purpose' => CodePurpose::Login,
            'code' => '1234',
            'expires_at' => now()->addMinutes(10),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['expires_at' => now()->subMinute()]);
    }
}
