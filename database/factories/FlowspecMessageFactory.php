<?php

namespace Database\Factories;

use App\Models\FlowspecChat;
use App\Models\FlowspecMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FlowspecMessage>
 */
class FlowspecMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'flowspec_chat_id' => FlowspecChat::factory(),
            'role'             => 'user',
            'content'          => fake()->paragraph(),
        ];
    }

    public function assistant(): static
    {
        return $this->state(['role' => 'assistant']);
    }
}
