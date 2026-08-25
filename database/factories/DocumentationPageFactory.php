<?php

namespace Database\Factories;

use App\Models\DocumentationPage;
use App\Models\Solution;
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
            'container_type' => Solution::class,
            'container_id'   => Solution::factory(),
            'parent_id'      => null,
            'title'          => rtrim($title, '.'),
            'slug'           => Str::slug($title) . '-' . Str::lower(Str::random(4)),
            'documentation'  => null,
            'position'       => 0,
        ];
    }

    /**
     * A subpage of `$parent`, in the same container — the only legal nesting
     * (see DocumentationPage: the tree is two levels deep, and a child always
     * shares its parent's container).
     */
    public function childOf(DocumentationPage $parent): static
    {
        return $this->state(fn () => [
            'container_type' => $parent->container_type,
            'container_id'   => $parent->container_id,
            'parent_id'      => $parent->id,
        ]);
    }
}
