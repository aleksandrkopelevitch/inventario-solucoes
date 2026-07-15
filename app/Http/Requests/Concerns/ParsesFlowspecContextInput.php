<?php

namespace App\Http\Requests\Concerns;

use App\Rules\FlowspecDocumentReference;

/**
 * Regras e parsing compartilhados por StoreFlowspecChatRequest e
 * StoreFlowspecMessageRequest para os dois chips pickers do composer:
 * `solutions` (Solutions explícitas — prioridade sobre a inferência por
 * nome) e `documents` (páginas/integrações específicas — quando presentes,
 * o FlowspecContextResolver usa exatamente essas, sem scoring nem corte por
 * orçamento).
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
