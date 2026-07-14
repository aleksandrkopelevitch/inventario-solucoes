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
            'title'          => rtrim($title, '.'),
            'slug'           => Str::slug($title) . '-' . Str::lower(Str::random(4)),
            'documentation'  => null,
            'position'       => 0,
        ];
    }
}
