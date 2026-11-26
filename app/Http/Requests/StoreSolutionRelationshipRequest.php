<?php

namespace App\Http\Requests;

use App\Enums\RelationshipDirection;
use App\Models\Solution;
use App\Models\SolutionRelationship;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Records an interconnection between the route's solution and another one,
 * from the solution page's "Relacionamentos" card — "+ Adicionar
 * interconexão", or the pencil on a row the documentation states (which
 * records the override for that pair).
 *
 * `direction` is read from the ROUTE's solution: `outgoing` = it calls the
 * other, `incoming` = the other calls it, `both`. No `undirected`: a person
 * recording an interconnection knows which way it runs, and the three choices
 * are the ones asked for.
 *
 * Authorized like every other relation edited on the page: `update` on the
 * solution (a catalog Editor).
 */
class StoreSolutionRelationshipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('solution')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'other_solution_id' => ['required', 'integer', 'exists:solutions,id', Rule::notIn([$this->route('solution')->getKey()])],
            'direction'         => ['required', Rule::in(self::DIRECTIONS)],
        ];
    }

    /** The three the screen offers. */
    public const DIRECTIONS = [RelationshipDirection::Outgoing->value, RelationshipDirection::Incoming->value, RelationshipDirection::Both->value];

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'other_solution_id.not_in' => 'Uma solução não se relaciona com ela mesma.',
        ];
    }

    /**
     * One record per pair. The picker leaves out the pairs that already have
     * one, so this catches a stale page — and names the pair, since "já
     * existe" alone does not say which row to edit instead.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $solution = $this->route('solution');
            $other = (int) $this->input('other_solution_id');
            $existing = SolutionRelationship::query()->forPair($solution->getKey(), $other)
                ->when($this->ignored(), fn ($q, SolutionRelationship $self) => $q->whereKeyNot($self->getKey()))
                ->exists();

            if ($existing) {
                $validator->errors()->add('other_solution_id', 'Já existe uma interconexão entre ' . $solution->name . ' e '
                    . Solution::query()->whereKey($other)->value('name') . ' — edite a existente.');
            }
        }];
    }

    /** The record being edited, which the uniqueness check must not count. */
    protected function ignored(): ?SolutionRelationship
    {
        return null;
    }

    public function direction(): RelationshipDirection
    {
        return RelationshipDirection::from((string) $this->validated('direction'));
    }

    public function other(): Solution
    {
        return Solution::query()->findOrFail((int) $this->validated('other_solution_id'));
    }
}
