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
        return [
            'solutions'         => ['nullable', 'array'],
            'solutions.*.value' => ['required', 'integer', 'exists:solutions,id'],
            'solutions.*.label' => ['nullable', 'string'],
            'documents'         => ['nullable', 'array'],
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
