<?php

namespace App\Http\Requests\Concerns;

use App\Rules\FlowspecDocumentReference;
use App\Rules\ValidJson;

/**
 * Rules and parsing shared by StoreFlowspecChatRequest and
 * StoreFlowspecMessageRequest for the composer's two chips pickers:
 * `solutions` (explicit Solutions — takes priority over name-based inference)
 * and `documents` (specific pages/integrations — when present,
 * FlowspecContextResolver uses exactly those, with no scoring or budget
 * cutoff) — plus the optional `reference_flowspec` field (a pasted pipeline
 * JSON used as the base for the request, normalized before it hits the prompt).
 */
trait ParsesFlowspecContextInput
{
    /** @return array<string, mixed> */
    protected function contextRules(): array
    {
        // `max` bounds both pickers: each element costs a DB lookup at
        // validation time (exists / FlowspecDocumentReference) and, for
        // documents in explicit mode, embeds whole documentation into the LLM
        // prompt with no budget cutoff — so an unbounded array is a real
        // query + prompt-cost amplifier from a crafted request. 20 is far above
        // any realistic hand-picked selection in the chips UI.
        return [
            'solutions'         => ['nullable', 'array', 'max:20'],
            'solutions.*.value' => ['required', 'integer', 'exists:solutions,id'],
            'solutions.*.label' => ['nullable', 'string'],
            'documents'         => ['nullable', 'array', 'max:20'],
            'documents.*.value' => ['required', 'string', new FlowspecDocumentReference],
            'documents.*.label' => ['nullable', 'string'],
            // Optional pasted pipeline used as the base for the request. Its
            // own (large) ceiling — the prose `message` stays at max:8000.
            'reference_flowspec' => ['nullable', 'string', 'max:' . config('services.flowspec.max_reference_chars'), new ValidJson],
        ];
    }

    public function referenceFlowspec(): ?string
    {
        return $this->validated('reference_flowspec') ?: null;
    }

    /** @return list<int> */
    public function solutionIds(): array
    {
        return collect($this->validated('solutions'))->pluck('value')->map(intval(...))->all();
    }

    /** @return list<array{type: string, id: int}> */
    public function documentRefs(): array
    {
        return collect($this->validated('documents'))
            ->pluck('value')
            ->map(function (string $ref) {
                [$type, $id] = explode(':', $ref, 2);

                return ['type' => $type, 'id' => (int) $id];
            })
            ->all();
    }
}
