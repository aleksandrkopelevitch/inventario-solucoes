<?php

namespace Database\Factories;

use App\Models\DocumentationChat;
use App\Models\DocumentationChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentationChatMessage>
 */
class DocumentationChatMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'documentation_chat_id' => DocumentationChat::factory(),
            'role'                  => 'user',
            'content'               => fake()->paragraph(),
        ];
    }

    public function assistant(): static
    {
        return $this->state(['role' => 'assistant']);
    }
}
