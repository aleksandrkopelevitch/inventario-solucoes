<?php

namespace App\Mcp\Support;

use App\Enums\ChainNodeKind;
use App\Enums\PersonSolutionRole;
use App\Enums\Protocol;
use App\Models\Company;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Person;
use App\Models\Solution;
use App\Support\ChainLabeler;

/**
 * Turns a model into the array a tool answers with.
 *
 * One class rather than a shaping method per tool, because the shapes have to
 * AGREE: `search_solutions` returns summaries and `get_solution` returns the
 * whole record, and a model that learned `slug` from one and met `id` in the
 * other cannot chain the two. So a summary is a strict subset of the full
 * record, and every reference from one thing to another is a SLUG.
 *
 * That last rule is the load-bearing one. A slug is what the next tool call
 * takes, what the app's own URL shows and what survives a database reload
 * between environments (§ A slug is lowercase ASCII, always). Numeric ids are
 * absent from every payload here on purpose: an id the model quotes back is an
 * id it cannot do anything with, and one it quotes to a PERSON is a number that
 * means nothing on any screen they can open.
 *
 * `url` is included for the same reason, inverted: a model answering a question
 * should be able to hand somebody the page to go and read. Those URLs are behind
 * `auth` and that is fine — the person following one is a person, and they log
 * in.
 */
class Presenter
{
    public function __construct(private readonly ChainLabeler $labeler) {}

    /* ------------------------------------------------------------------ */
    /*  Solutions */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    public function solutionSummary(Solution $solution): array
    {
        return $this->compact([
            'slug'        => $solution->slug,
            'name'        => $solution->name,
            'category'    => $solution->category_label,
            'status'      => $solution->status_label,
            'criticality' => $solution->criticality_label,
            'directorate' => $solution->directorate,
            'vendor'      => $solution->relationLoaded('vendor') ? $solution->vendor?->name : null,
            'url'         => route('solutions.show', $solution),
        ]);
    }

    /** @return array<string, mixed> */
    public function solution(Solution $solution): array
    {
        return $this->compact([
            ...$this->solutionSummary($solution),
            'description'     => $solution->description,
            'environment'     => $solution->environment_label,
            'cloud'           => $solution->cloud_label,
            'support_type'    => $solution->support_type_label,
            'contract_status' => $solution->contract_status_label,
            'support_note'    => $solution->support_operation_note,
            'vendor'          => $solution->vendor ? $this->companySummary($solution->vendor) : null,
            'people'          => $solution->people
                ->map(fn (Person $person) => $this->compact([
                    'slug'       => $person->slug,
                    'name'       => $person->name,
                    'role'       => $this->roleLabel($person->pivot->role),
                    'is_primary' => (bool) $person->pivot->is_primary,
                ]))
                ->all(),
            'diagrams' => $solution->diagrams
                ->map(fn (Diagram $diagram) => $this->diagramSummary($diagram))
                ->all(),
            // Published cadernos only — the same reading `/docs` makes. A
            // solution documented exclusively in an unpublished caderno answers
            // with an empty list here, which is why the tool's own description
            // says so rather than letting the emptiness be read as "não
            // documentado".
            'published_notebooks' => $solution->notebooks
                ->filter(fn (Notebook $notebook) => $notebook->isPublished())
                ->map(fn (Notebook $notebook) => $this->notebookSummary($notebook))
                ->values()
                ->all(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Diagrams */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    public function diagramSummary(Diagram $diagram): array
    {
        return $this->compact([
            'slug'   => $diagram->slug,
            'name'   => $diagram->name,
            'status' => $diagram->status?->label(),
            'url'    => route('diagrams.show', $diagram),
        ]);
    }

    /**
     * The drawing, as a graph the model can read.
     *
     * Both the structured `nodes`/`edges` AND a rendered `topology` line, and
     * the redundancy is deliberate: the arrays are what a model needs to answer
     * "quantos sistemas passam por aqui", while "A -> B -> C" is what it needs
     * to answer "como funciona" without reconstructing the walk from indices.
     * `ChainLabeler::label()` is the same sentence the app puts under the
     * drawing, so the two surfaces cannot drift.
     *
     * `from`/`to` stay INDICES into `nodes`, exactly as stored (§ Diagram
     * topology invariant). Rewriting them into labels would be lossy the moment
     * two blocks share a name, which in this corpus they do.
     *
     * @return array<string, mixed>
     */
    public function diagram(Diagram $diagram): array
    {
        $chain = $diagram->chainData() ?? [];
        $solutions = $this->labeler->resolveSolutions(collect([$chain]));

        return $this->compact([
            ...$this->diagramSummary($diagram),
            'criticality'  => $diagram->criticality_label,
            'protocol'     => $this->protocolLabel($diagram->protocol),
            'sync_mode'    => $diagram->sync_mode?->label(),
            'topology'     => $chain ? $this->labeler->label($chain, $solutions) : null,
            'participants' => $diagram->participants
                ->map(fn (Solution $solution) => $this->solutionSummary($solution))
                ->all(),
            'nodes' => collect($chain['nodes'] ?? [])
                ->map(fn (array $node) => $this->compact([
                    'kind'  => ChainNodeKind::fromNode($node)->value,
                    'label' => $this->labeler->nodeLabel($node, $solutions),
                    // Only a `system` block names a Solution, so this is the one
                    // field that says "this block IS a catalogued system" rather
                    // than free text somebody typed.
                    'solution' => ChainNodeKind::fromNode($node)->referencesSolution()
                        ? $solutions[$node['solution_id'] ?? null]?->slug
                        : null,
                ]))
                ->values()
                ->all(),
            'edges' => collect($chain['edges'] ?? [])
                ->map(fn (array $edge) => $this->compact([
                    'from'     => $edge['from'] ?? 0,
                    'to'       => $edge['to'] ?? 0,
                    'arrow'    => $edge['arrow'] ?? '->',
                    'protocol' => $this->protocolLabel($edge['protocol'] ?? null),
                ]))
                ->values()
                ->all(),
        ]);
    }

    /**
     * The pivot's `role` is a plain string column holding a
     * `PersonSolutionRole` value, so it is labelled here rather than cast on the
     * relation — `withPivot()` takes no casts, and `technical` says less to a
     * model than "Responsável técnico" does. `tryFrom()` rather than `from()`
     * because a row written before a case was renamed must degrade to its raw
     * value, not throw in the middle of a read.
     */
    private function roleLabel(?string $role): ?string
    {
        return blank($role) ? null : (PersonSolutionRole::tryFrom($role)?->label() ?? $role);
    }

    /**
     * A protocol is free text with a known vocabulary in front of it, so the
     * label is resolved the way `ChainGraph::resolveProtocol()` does it —
     * `tryFrom()` and fall back to what was typed. An Eloquent enum cast would
     * throw on the free-text half (§ Diagram, `casts()`).
     */
    private function protocolLabel(?string $protocol): ?string
    {
        return blank($protocol) ? null : (Protocol::tryFrom($protocol)?->label() ?? $protocol);
    }

    /* ------------------------------------------------------------------ */
    /*  Documentation */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    public function notebookSummary(Notebook $notebook): array
    {
        return $this->compact([
            'slug'         => $notebook->slug,
            'name'         => $notebook->name,
            'published_at' => $notebook->published_at?->toDateString(),
            'solutions'    => $notebook->relationLoaded('solutions')
                ? $notebook->solutions->pluck('slug')->all()
                : null,
            'url' => $notebook->knowledgeBaseUrl(),
        ]);
    }

    /**
     * A page WITHOUT its body — the unit both the tree and the search results
     * are built from.
     *
     * @return array<string, mixed>
     */
    public function pageSummary(DocumentationPage $page, Notebook $notebook, ?int $depth = null): array
    {
        return $this->compact([
            'slug'     => $page->slug,
            'title'    => $page->title,
            'notebook' => $notebook->slug,
            'depth'    => $depth,
            'url'      => route('docs.page', [$notebook, $page]),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  People and companies */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    public function personSummary(Person $person): array
    {
        return $this->compact([
            'slug'      => $person->slug,
            'name'      => $person->name,
            'job_title' => $person->job_title,
            'company'   => $person->relationLoaded('company') ? $person->company?->name : null,
            'url'       => route('people.show', $person),
        ]);
    }

    /** @return array<string, mixed> */
    public function person(Person $person): array
    {
        return $this->compact([
            ...$this->personSummary($person),
            'email'   => $person->email,
            'phone'   => $person->phone,
            'notes'   => $person->notes,
            'company' => $person->company ? $this->companySummary($person->company) : null,
            // The repeater beside the two columns — a person's second address,
            // a mobile, a WhatsApp. `Person::scopeFilter()` searches it, so a
            // person found by a phone number that lives only here would
            // otherwise come back without the number that matched.
            'contacts' => $person->contacts
                ->map(fn ($contact) => $this->compact([
                    'type'       => $contact->type?->label(),
                    'value'      => $contact->value,
                    'is_primary' => (bool) $contact->is_primary,
                ]))
                ->all(),
            'solutions' => $person->solutions
                ->map(fn (Solution $solution) => $this->compact([
                    'slug'       => $solution->slug,
                    'name'       => $solution->name,
                    'role'       => $this->roleLabel($solution->pivot->role),
                    'is_primary' => (bool) $solution->pivot->is_primary,
                ]))
                ->all(),
        ]);
    }

    /** @return array<string, mixed> */
    public function companySummary(Company $company): array
    {
        return $this->compact([
            'slug'    => $company->slug,
            'name'    => $company->name,
            'kind'    => $company->kind?->label(),
            'website' => $company->website,
            'url'     => route('companies.show', $company),
        ]);
    }

    /** @return array<string, mixed> */
    public function company(Company $company): array
    {
        return $this->compact([
            ...$this->companySummary($company),
            'notes'  => $company->notes,
            'people' => $company->people
                ->map(fn (Person $person) => $this->personSummary($person))
                ->all(),
            'provided_solutions' => $company->providedSolutions
                ->map(fn (Solution $solution) => $this->solutionSummary($solution))
                ->all(),
        ]);
    }

    /**
     * Drops nulls and empty lists.
     *
     * Not cosmetic: most of this catalog's optional columns are blank on most
     * rows, so a payload that keeps them spends a third of its tokens on
     * `"cloud": null` — and, worse, reads to a model as a fact ("this solution
     * HAS no cloud") when it means "ninguém preencheu". Absence says the second
     * thing and costs nothing. `false` and `0` survive, being answers.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function compact(array $values): array
    {
        return array_filter(
            $values,
            fn ($value) => $value !== null && $value !== '' && $value !== [],
        );
    }
}
