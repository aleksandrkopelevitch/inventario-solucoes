<?php

namespace App\Mcp\Support;

use App\Enums\PersonSolutionRole;
use App\Models\Company;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Person;
use App\Models\Solution;
use App\Support\Documentation\UmlDiagram;
use App\Support\Images\PublicImage;
use App\Support\Relationships\Relationship;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

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
    /**
     * @param  Collection<int, Relationship>|null  $relationships  null leaves the key out
     */
    public function solution(Solution $solution, bool $withUnpublished = false, ?Collection $relationships = null, bool $withEvidence = false): array
    {
        return $this->compact([
            ...$this->solutionSummary($solution),
            'description'     => $solution->description,
            'environment'     => $solution->environment_label,
            'cloud'           => $solution->cloud_label,
            'support_type'    => $solution->support_type_label,
            'contract_status' => $solution->contract_status_label,
            'support_note'    => $solution->support_operation_note,
            'logo'            => $this->imageUrl($solution->logo_path),
            'vendor'          => $solution->vendor ? $this->companySummary($solution->vendor) : null,
            'people'          => $solution->people
                ->map(fn (Person $person) => $this->compact([
                    'slug'       => $person->slug,
                    'name'       => $person->name,
                    'role'       => $this->roleLabel($person->pivot->role),
                    'is_primary' => (bool) $person->pivot->is_primary,
                ]))
                ->all(),
            'relationships' => $relationships?->map(fn (Relationship $r) => $this->relationship($r, $withEvidence, $withUnpublished))->all(),
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
            // Only for a connection that writes the documentation
            // (ReadableNotebooks) — a separate key, so the published list keeps
            // meaning exactly what its name says.
            'unpublished_notebooks' => $withUnpublished
                ? $solution->notebooks
                    ->reject(fn (Notebook $notebook) => $notebook->isPublished())
                    ->map(fn (Notebook $notebook) => $this->notebookSummary($notebook))
                    ->values()
                    ->all()
                : null,
        ]);
    }

    /**
     * One relationship of a solution, read from its side: "chama", "é chamada
     * por", "mútua" or "sem sentido definido".
     *
     * The relationship itself is a fact about two catalog records and goes to
     * every caller of `get_solution`. Its EVIDENCE — where the documentation
     * states it — is cut to what this caller may read: nothing without the
     * documentation module, and an unpublished caderno only for a connection
     * that writes the documentation (ReadableNotebooks), marked as such. An
     * unpublished page's title never reaches anybody else.
     *
     * @return array<string, mixed>
     */
    public function relationship(Relationship $relationship, bool $withEvidence, bool $withUnpublished): array
    {
        $evidence = collect($withEvidence ? $relationship->evidence : [])
            ->filter(fn (array $e) => $withUnpublished || $e['page']->notebook->isPublished())
            ->map(fn (array $e) => $this->compact([
                'notebook'  => $e['page']->notebook->slug,
                'page_slug' => $e['page']->slug,
                'page'      => $e['page']->title,
                'diagram'   => $e['title'] ?? UmlDiagram::TYPES[$e['type']] ?? null,
                'published' => $e['page']->notebook->isPublished() ? null : false,
            ]))
            ->values()
            ->all();

        return $this->compact([
            'solution'  => ['slug' => $relationship->other->slug, 'name' => $relationship->other->name],
            'direction' => $relationship->direction->label(),
            'source'    => match ($relationship->source()) {
                'manual'   => 'cadastrado',
                'adjusted' => 'cadastro sobre a documentação',
                default    => 'documentado',
            },
            // What the documentation says on its own, when a record overrides it.
            'documented_direction' => $relationship->documented?->label(),
            'recorded_by'          => $relationship->manual?->creator?->name,
            // One list per route, platforms in the order a call crosses them
            // from this solution.
            'via' => $relationship->direct
                ? null
                : array_map(fn (array $route) => array_map(fn (Solution $p) => ['slug' => $p->slug, 'name' => $p->name], $route), $relationship->via),
            'labels'   => $relationship->labels,
            'evidence' => $evidence,
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

    /* ------------------------------------------------------------------ */
    /*  Documentation */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    public function notebookSummary(Notebook $notebook): array
    {
        $published = $notebook->isPublished();

        return $this->compact([
            'slug' => $notebook->slug,
            'name' => $notebook->name,
            // Said on every caderno, because only a writing connection is ever
            // shown an unpublished one (ReadableNotebooks) — and it must not
            // cite that text as "da base publicada".
            'published'    => $published,
            'published_at' => $notebook->published_at?->toDateString(),
            'solutions'    => $notebook->relationLoaded('solutions')
                ? $notebook->solutions->pluck('slug')->all()
                : null,
            // An unpublished caderno has no address in the knowledge base; the
            // editor is where it lives.
            'url' => $published ? $notebook->knowledgeBaseUrl() : route('notebooks.show', $notebook),
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
            'url'      => $notebook->isPublished()
                ? route('docs.page', [$notebook, $page])
                : route('notebooks.pages.edit', [$notebook, $page]),
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
            'photo'   => $this->imageUrl($person->photo_path),
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
            'logo'   => $this->imageUrl($company->logo_path),
            'people' => $company->people
                ->map(fn (Person $person) => $this->personSummary($person))
                ->all(),
            'provided_solutions' => $company->providedSolutions
                ->map(fn (Solution $solution) => $this->solutionSummary($solution))
                ->all(),
        ]);
    }

    /** The ORIGINAL picture's URL (not the thumbnail) — what an MCP write just stored, to check against. */
    private function imageUrl(?string $path): ?string
    {
        return filled($path) ? Storage::disk(PublicImage::DISK)->url($path) : null;
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
