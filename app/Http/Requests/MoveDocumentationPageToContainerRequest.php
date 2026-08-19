<?php

namespace App\Http\Requests;

use App\Models\DocumentationGroup;
use App\Models\Solution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Re-files a page under another container. Mirror of MoveDocumentationPageRequest
 * (which reorders WITHIN a container) — same authorize() shape, so either
 * controller can use it.
 *
 * Note this only authorizes the SOURCE, via the route model. The destination is
 * a different record with its own policy, and a failure there has to be a 403,
 * not a 422 — so the controller resolves it and calls `authorize('update', …)`
 * on it separately. Don't fold that check into a validation rule.
 */
class MoveDocumentationPageToContainerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = $this->route('solution') ?? $this->route('group');

        return $model !== null && $this->user()->can('update', $model);
    }

    /**
     * The rail sends ONE value, because that is all a `<select>` can carry:
     * `solution:12` / `group:3`. Splitting it here is what lets each half be
     * validated for real instead of trusting a regex over the pair.
     */
    protected function prepareForValidation(): void
    {
        [$type, $id] = array_pad(explode(':', (string) $this->input('container'), 2), 2, null);

        $this->merge([
            'container_type' => $type,
            'container_id'   => $id,
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'container_type' => ['required', Rule::in(['solution', 'group'])],
            'container_id'   => [
                'required',
                'integer',
                Rule::exists($this->destinationTable(), 'id'),
                function (string $attribute, mixed $value, callable $fail): void {
                    // Moving a page to where it already is would pass every
                    // other rule and answer "Página movida." having done
                    // nothing — the rail hides the current container from the
                    // options, so reaching here means the request was forged
                    // or the rail was stale.
                    if ($this->destination()?->is($this->currentContainer())) {
                        $fail('A página já está neste destino.');
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'container_type.required' => 'Escolha o destino da página.',
            'container_type.in'       => 'Destino inválido.',
            'container_id.required'   => 'Escolha o destino da página.',
            'container_id.exists'     => 'O destino escolhido não existe mais.',
        ];
    }

    /** The Solution or DocumentationGroup this page is being moved TO. */
    public function destination(): ?Model
    {
        $id = $this->input('container_id');

        if (! is_numeric($id)) {
            return null;
        }

        return $this->input('container_type') === 'group'
            ? DocumentationGroup::find((int) $id)
            : Solution::find((int) $id);
    }

    /** The Solution or DocumentationGroup it is being moved FROM (the route model). */
    public function currentContainer(): ?Model
    {
        return $this->route('solution') ?? $this->route('group');
    }

    private function destinationTable(): string
    {
        return $this->input('container_type') === 'group' ? 'documentation_groups' : 'solutions';
    }
}
