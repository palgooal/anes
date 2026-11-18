<?php

namespace Database\Factories;

use App\AccountStatus;
use App\Models\User;
use App\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Staff member (a guide) by default. Use the states for other roles.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'role' => UserRole::Guide,
            'status' => AccountStatus::Active,
            'email' => fake()->unique()->safeEmail(),
            'phone' => '9665'.fake()->unique()->numerify('########'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A staff member with the given role.
     */
    public function role(UserRole $role): static
    {
        return $this->state(fn (array $attributes) => ['role' => $role]);
    }

    public function admin(): static
    {
        return $this->role(UserRole::Admin);
    }

    /**
     * A patient: signs in with a phone number only (no email or password).
     */
    public function patient(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Patient,
            'email' => null,
            'email_verified_at' => null,
            'password' => null,
        ]);
    }

    /**
     * A companion following the given patient (or a new one).
     */
    public function companion(?User $patient = null): static
    {
        return $this->patient()->state(fn (array $attributes) => [
            'role' => UserRole::Companion,
            'patient_id' => $patient ?? User::factory()->patient(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => ['status' => AccountStatus::Pending]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => ['status' => AccountStatus::Suspended]);
    }
}
