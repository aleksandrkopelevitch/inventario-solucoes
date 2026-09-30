<?php

namespace App\Http\Requests;

use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Desenhar esta página" — the answer to the dialog that opens before anything
 * is generated: draw a NEW diagram of this caderno (under the name typed), or
 * draw over one it already has.
 *
 * `target` is one field rather than a mode plus a diagram: the dialog is one
 * radio group, "Criar novo" being its first option. It carries the diagram's
 * SLUG, and the slug must be one of THIS caderno's — an overwrite that could
 * reach another caderno's drawing would rewrite something that caderno's pages
 * cite, from a screen that never showed it.
 *
 * Asked BEFORE the model call on purpose: the call is synchronous and can take
 * a minute, and asking afterwards would mean keeping its result somewhere
 * until the answer arrived.
 */
class DrawNotebookPageRequest extends FormRequest
{
    public const NEW = 'new';

    /** Memoised: authorize(), the controller and diagramName() all ask. */
    private Diagram|false|null $target = false;

    public function authorize(): bool
    {
        /** @var Notebook $notebook */
        $notebook = $this->route('notebook');
        /** @var DocumentationPage $page */
        $page = $this->route('page');

        // Already in hand from the route binding — without it the policy is
        // what lazy-loads it (see .claude/rules/eloquent-strict-and-search.md).
        $page->setRelation('notebook', $notebook);

        $user = $this->user();

        if (! $user?->can('view', $page)) {
            return false;
        }

        // Two abilities, because two things happen: a page is read and a
        // drawing is written. A Viewer may well be able to read this caderno,
        // and must not be able to put a diagram in the catalog from it.
        $target = $this->targetDiagram();

        return $target ? $user->can('update', $target) : $user->can('create', Diagram::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Notebook $notebook */
        $notebook = $this->route('notebook');

        return [
            'target' => ['required', 'string', Rule::in([
                self::NEW,
                ...$notebook->diagrams()->pluck('slug')->all(),
            ])],
            'name' => ['exclude_unless:target,' . self::NEW, 'required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'target.required' => 'Escolha criar um diagrama novo ou substituir um deste caderno.',
            'target.in'       => 'Esse diagrama não pertence a este caderno.',
            'name.required'   => 'Dê um nome ao novo diagrama.',
        ];
    }

    /** The caderno's diagram to draw over, or null for a new one. */
    public function targetDiagram(): ?Diagram
    {
        if ($this->target !== false) {
            return $this->target;
        }

        $slug = (string) $this->input('target');

        if ($slug === '' || $slug === self::NEW) {
            return $this->target = null;
        }

        /** @var Notebook $notebook */
        $notebook = $this->route('notebook');

        return $this->target = $notebook->diagrams()->where('slug', $slug)->first();
    }

    /** The name typed for a new diagram; null when drawing over one. */
    public function diagramName(): ?string
    {
        return $this->targetDiagram() ? null : trim((string) $this->validated('name'));
    }
}
