<?php

namespace Database\Factories;

use App\Models\DocumentationChat;
use App\Models\DocumentationPage;
use App\Models\Notebook;
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
        // A page in a caderno is the only shape a chat has, so it is the
        // default rather than something callers have to assemble.
        $notebook = Notebook::factory()->create();
        $page = DocumentationPage::factory()->for($notebook)->create();

        return [
            'user_id'     => User::factory(),
            'target_type' => DocumentationPage::class,
            'target_id'   => $page->id,
            'notebook_id' => $notebook->id,
        ];
    }
}
