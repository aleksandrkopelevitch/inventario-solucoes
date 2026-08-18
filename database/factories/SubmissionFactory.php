<?php

namespace Database\Factories;

use App\Enums\SubmissionStatus;
use App\Models\Solution;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Submission>
 */
class SubmissionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'CATI ' . $this->faker->unique()->words(3, true),
            // Derived from the RESOLVED name, so overriding the name in a test
            // doesn't leave the slug pointing at the factory's random one.
            'slug' => fn (array $attributes) => Str::slug($attributes['name']),
            // Null by default: a submission can propose something that isn't
            // in the catalog yet, and tests that care about inventory facts
            // attach a Solution explicitly via forSolution().
            'solution_id'         => null,
            'requester_person_id' => null,
            'created_by_id'       => User::factory(),
            'status'              => SubmissionStatus::Draft,
            'ticket_reference'    => null,
            'committee_date'      => null,
        ];
    }

    /** The eleven section rows a real submission always has (SubmissionController does this on store). */
    public function withSections(): static
    {
        return $this->afterCreating(fn (Submission $submission) => $submission->ensureSections());
    }

    public function forSolution(Solution $solution): static
    {
        return $this->state(fn () => ['solution_id' => $solution->id]);
    }

    public function status(SubmissionStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
