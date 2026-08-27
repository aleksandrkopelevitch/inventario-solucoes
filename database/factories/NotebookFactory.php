<?php

namespace Database\Factories;

use App\Models\Notebook;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Notebook>
 */
class NotebookFactory extends Factory
{
    protected $model = Notebook::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'name' => Str::ucfirst($name),
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(4)),
            // Not shared by default: a magic link is a deliberate gesture, and
            // a factory that hands one out makes every "is this public?" test
            // pass for the wrong reason.
            'public_token' => null,
        ];
    }
}
