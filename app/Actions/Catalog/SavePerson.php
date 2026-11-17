<?php

namespace App\Actions\Catalog;

use App\Models\Person;

/**
 * Creates or updates a person's OWN columns from an already-VALIDATED attribute
 * array (`PersonRules`), generating the slug when none was given.
 *
 * Shared by the catalog's side panel and the MCP write tools. The panel's two
 * repeaters (linked systems, additional contacts) stay in `PersonController`:
 * they read the form's own markers (`solutions_present`, `contacts_present`)
 * and nothing else sends them.
 */
class SavePerson
{
    /** @param  array<string, mixed>  $data */
    public function handle(array $data, ?Person $person = null): Person
    {
        $data['slug'] = filled($data['slug'] ?? null)
            ? $data['slug']
            : ($person?->slug ?? Person::uniqueSlug($data['name'], $person));

        if ($person) {
            $person->update($data);

            return $person;
        }

        return Person::create($data);
    }
}
