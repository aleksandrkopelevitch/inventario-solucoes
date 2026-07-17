<?php

namespace App\Http\Requests\Concerns;

use App\Rules\FlowspecDocumentReference;

/**
 * Rules and parsing shared by StoreFlowspecChatRequest and
 * StoreFlowspecMessageRequest for the composer's two chips pickers:
 * `solutions` (explicit Solutions — takes priority over name-based inference)
 * and `documents` (specific pages/integrations — when present,
 * FlowspecContextResolver uses exactly those, with no scoring or budget cutoff).
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
        ];
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
