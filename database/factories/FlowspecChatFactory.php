<?php

namespace Database\Factories;

use App\Models\FlowspecChat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FlowspecChat>
 */
class FlowspecChatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title'   => fake()->sentence(4),
        ];
    }
}
