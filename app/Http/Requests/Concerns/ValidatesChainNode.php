<?php

namespace App\Http\Requests\Concerns;

use App\Enums\ChainNodeKind;
use Illuminate\Validation\Rule;

/**
 * The three fields that make up a chain node (`chain.nodes[i]`) in the F3
 * data-viz — `kind` + `solution_id`/`label` —, shared by
 * AddChainNodeRequest (a brand-new block) and
 * UpdateChainNodeRequest (retitling / converting an existing one),
 * which validate exactly the same shape: the block panel and the block's title
 * editor are the same form, one creating and the other editing.
 *
 * Only a system node may reference a registered Solution (see
 * `ChainNodeKind`), so for a decision/actor block `solution_id` is dropped
 * before validation and the free-text label becomes required.
 */
trait ValidatesChainNode
{
    /** The requested kind, defaulting to system (the shape used before kinds existed). */
    protected function chainNodeKind(): ChainNodeKind
    {
        return ChainNodeKind::tryFrom((string) $this->input('kind')) ?? ChainNodeKind::System;
    }

    /**
     * `kind` is validated against only the PICKABLE kinds (`ChainNodeKind::pickable()`)
     * — not every enum case — so a client can never create or convert a block
     * into `Image` through these generic endpoints; that kind is only ever
     * produced by `SolutionIntegrationController::addImageNode()`, which always
     * pairs it with an uploaded `media_id` in the same request.
     *
     * @return array<int, string>
     */
    private function pickableKindValues(): array
    {
        return array_map(fn (ChainNodeKind $k) => $k->value, array_filter(ChainNodeKind::cases(), fn (ChainNodeKind $k) => $k->pickable()));
    }

    /**
     * On a decision/actor block `solution_id` isn't a field that exists at all,
     * so it's left OUT of the rules rather than allowed-and-ignored: absent from
     * the rules means absent from `validated()`, and the controller's
     * `chainNode()` writes the node with `solution_id => null`. A client that
     * sends one anyway is silently dropped, which is the intent.
     *
     * @return array<string, mixed>
     */
    protected function chainNodeRules(): array
    {
        if (! $this->chainNodeKind()->referencesSolution()) {
            return [
                'kind'  => ['nullable', Rule::in($this->pickableKindValues())],
                'label' => ['required', 'string', 'max:255'],
            ];
        }

        return [
            'kind'        => ['nullable', Rule::in($this->pickableKindValues())],
            'solution_id' => ['nullable', 'integer', 'exists:solutions,id', 'required_without:label'],
            'label'       => ['nullable', 'string', 'max:255', 'required_without:solution_id'],
        ];
    }

    /** @return array<string, string> */
    protected function chainNodeMessages(): array
    {
        return [
            'solution_id.required_without' => 'Escolha um sistema ou informe o texto livre.',
            'label.required_without'       => 'Escolha um sistema ou informe o texto livre.',
            'label.required'               => 'Informe o texto do bloco.',
        ];
    }

    /**
     * Normalizes the select's "free" sentinel (free text) to a null
     * solution_id — and forces it to null for the kinds that can't point at a
     * Solution, so a decision/actor/start/end block always ends up as pure
     * free text. A blank label falls back to the kind's `defaultLabel()`
     * (only `start`/`end` have one — "Início"/"Fim" — so those two are the
     * only kinds a client can create without typing anything).
     *
     * An OMITTED kind is filled in with the default (system); an unknown one
     * is deliberately left untouched, so the `Enum` rule rejects it instead of
     * this silently coercing a client bug into a system block.
     */
    protected function prepareForValidation(): void
    {
        $solutionId = $this->input('solution_id');
        $solutionId = is_numeric($solutionId) && $this->chainNodeKind()->referencesSolution() ? (int) $solutionId : null;

        $label = trim((string) $this->input('label', '')) ?: null;
        $label ??= $solutionId ? null : $this->chainNodeKind()->defaultLabel();

        $this->merge([
            'solution_id' => $solutionId,
            'label'       => $solutionId ? null : $label,
        ]);

        if (blank($this->input('kind'))) {
            $this->merge(['kind' => ChainNodeKind::System->value]);
        }
    }
}
