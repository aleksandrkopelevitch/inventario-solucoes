<?php

namespace Database\Factories;

use App\Enums\AccessLevel;
use App\Enums\AccessModule;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
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
            'name'              => fake()->name(),
            'email'             => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password'          => static::$password ??= Hash::make('password'),
            'remember_token'    => Str::random(10),
            'role'              => UserRole::Member->value,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => UserRole::Admin->value]);
    }

    /**
     * A Reader in `$modules` — every module when none is named. Explicit,
     * because a new account's defaults leave the Especialista and the Comitê
     * at None (`AccessModule::defaultLevel()`).
     */
    public function reader(AccessModule ...$modules): static
    {
        $modules = $modules ?: AccessModule::cases();

        return $this->state(fn (array $attributes) => [
            'role'   => UserRole::Member->value,
            'access' => collect($modules)->mapWithKeys(fn (AccessModule $m) => [$m->value => AccessLevel::Reader->value])->all(),
        ]);
    }

    /**
     * An Editor in `$modules` — every module when none is named, which is what
     * the app-wide `writer` tier used to be.
     */
    public function editor(AccessModule ...$modules): static
    {
        $modules = $modules ?: AccessModule::cases();

        return $this->state(fn (array $attributes) => [
            'role'   => UserRole::Member->value,
            'access' => collect($modules)->mapWithKeys(fn (AccessModule $m) => [$m->value => AccessLevel::Editor->value])->all(),
        ]);
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
}
