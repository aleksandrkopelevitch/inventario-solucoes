<?php

namespace Database\Factories;

use App\Models\DocumentationGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentationGroup>
 */
class DocumentationGroupFactory extends Factory
{
    protected $model = DocumentationGroup::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'name' => Str::ucfirst($name),
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(4)),
        ];
    }
}
