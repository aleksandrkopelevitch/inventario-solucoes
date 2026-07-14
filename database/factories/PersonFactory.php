<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    public function definition(): array
    {
        $name = fake()->unique()->name();

        return [
            'name'       => $name,
            'slug'       => Str::slug($name) . '-' . Str::lower(Str::random(4)),
            'company_id' => null,
            'job_title'  => fake()->optional()->jobTitle(),
            'email'      => fake()->optional()->safeEmail(),
            'phone'      => fake()->optional()->phoneNumber(),
            'photo_path' => null,
            'notes'      => null,
        ];
    }

    public function withCompany(): static
    {
        return $this->state(fn () => ['company_id' => Company::factory()]);
    }
}
