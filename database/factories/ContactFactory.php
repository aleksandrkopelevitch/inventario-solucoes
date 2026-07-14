<?php

namespace Database\Factories;

use App\Enums\ContactType;
use App\Models\Contact;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'person_id'  => Person::factory(),
            'type'       => ContactType::Email,
            'value'      => fake()->safeEmail(),
            'is_primary' => false,
        ];
    }
}
