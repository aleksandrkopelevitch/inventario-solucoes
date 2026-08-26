<?php

namespace Database\Factories;

use App\Models\DocumentationChat;
use App\Models\DocumentationPage;
use App\Models\Solution;
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
        // A page in a Solution is the only shape a chat has now (the Assistant
        // isn't wired into the standalone-group controller), so it's also the
        // default rather than something callers have to assemble.
        $solution = Solution::factory()->create();
        $page = DocumentationPage::factory()->for($solution, 'container')->create();

        return [
            'user_id'     => User::factory(),
            'target_type' => DocumentationPage::class,
            'target_id'   => $page->id,
            'solution_id' => $solution->id,
        ];
    }
}
