<?php

namespace Database\Factories;

use App\Models\DocumentationChat;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentationChat>
 */
class DocumentationChatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Integration is the simplest default target (no container branching);
        // callers targeting a DocumentationPage override target_type/target_id/solution_id.
        $integration = Integration::factory()->create();

        return [
            'user_id'     => User::factory(),
            'target_type' => Integration::class,
            'target_id'   => $integration->id,
            'solution_id' => $integration->source_solution_id,
        ];
    }
}
