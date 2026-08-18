<?php

namespace Database\Factories;

use App\Models\Submission;
use App\Models\SubmissionChat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmissionChat>
 */
class SubmissionChatFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id'       => User::factory(),
            'submission_id' => Submission::factory(),
        ];
    }
}
