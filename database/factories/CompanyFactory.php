<?php

namespace Database\Factories;

use App\Enums\CompanyKind;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name'      => $name,
            'slug'      => Str::slug($name) . '-' . Str::lower(Str::random(4)),
            'kind'      => CompanyKind::Vendor,
            'logo_path' => null,
            'website'   => fake()->optional()->url(),
            'notes'     => null,
        ];
    }

    public function internal(): static
    {
        return $this->state(fn () => ['kind' => CompanyKind::Internal]);
    }
}
