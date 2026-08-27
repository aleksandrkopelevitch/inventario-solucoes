<?php

namespace Database\Factories;

use App\Models\DocumentationPage;
use App\Models\Notebook;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentationPage>
 */
class DocumentationPageFactory extends Factory
{
    protected $model = DocumentationPage::class;

    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'notebook_id'   => Notebook::factory(),
            'parent_id'     => null,
            'title'         => rtrim($title, '.'),
            'slug'          => Str::slug($title) . '-' . Str::lower(Str::random(4)),
            'documentation' => null,
            'position'      => 0,
        ];
    }

    /**
     * A subpage of `$parent`, in the same caderno — the only legal nesting (see
     * DocumentationPage: a child always shares its parent's notebook).
     */
    public function childOf(DocumentationPage $parent): static
    {
        return $this->state(fn () => [
            'notebook_id' => $parent->notebook_id,
            'parent_id'   => $parent->id,
        ]);
    }
}
