<?php

namespace Database\Factories;

use App\Models\SubmissionChat;
use App\Models\SubmissionMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmissionMessage>
 */
class SubmissionMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_chat_id' => SubmissionChat::factory(),
            'role'               => 'user',
            'content'            => 'O SKBridge roda numa VM na Google Cloud.',
            'drafts'             => null,
            'source_ids'         => null,
            'meta'               => null,
            'applied_at'         => null,
        ];
    }

    /** @param  list<array{key: string, markdown: string}>|null  $drafts */
    public function assistant(?array $drafts = null): static
    {
        return $this->state(fn () => [
            'role'    => 'assistant',
            'content' => 'Anotei. Falta o custo de licenciamento — você já tem esse número?',
            'drafts'  => $drafts,
        ]);
    }
}
