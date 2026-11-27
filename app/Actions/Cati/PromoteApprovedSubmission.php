<?php

namespace App\Actions\Cati;

use App\Contracts\Documentable;
use App\Enums\SubmissionDiagramKind;
use App\Enums\SubmissionSectionKey;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Solution;
use App\Models\Submission;
use App\Models\SubmissionDiagram;
use Illuminate\Support\Str;

/**
 * Pushes an approved submission into the catalog.
 *
 * This is the flywheel, and without it the module is a slide factory: what the
 * committee approved would live in a `.pptx` and the inventory would keep
 * drifting until the next submission has to reconstruct the AS-IS by hand.
 *
 * It does not write topology. Who talks to whom is the documentation's
 * (the UML diagrams in caderno pages, and the interconnections people record
 * on the solution page); an approval publishes the proposal's prose and its
 * TO BE picture into the solution's caderno, and the relationships follow
 * once somebody documents them there. (Until 2026-11-27 an approval recorded
 * a topology for a person to apply into a catalog drawing; both went with
 * the topology drawings.)
 *
 * Idempotent by `promoted_at`, and it never overwrites an existing page — a
 * second run updates the page it created rather than stacking duplicates.
 */
class PromoteApprovedSubmission
{
    /**
     * Sections whose prose belongs in the solution's documentation. The
     * committee-process sections (costs, alternatives, the plan) stay on the
     * submission: they describe a DECISION, not how the system works, and a
     * documentation page that carries them ages badly.
     */
    private const PROMOTED = [
        SubmissionSectionKey::Summary,
        SubmissionSectionKey::Architecture,
        SubmissionSectionKey::OperatingModel,
        SubmissionSectionKey::DomainsData,
        SubmissionSectionKey::Standards,
        SubmissionSectionKey::LegacyImpact,
    ];

    public function __construct(private readonly RenderSubmissionMarkdown $renderer) {}

    /**
     * Where an approved submission's prose lands: a caderno the solution is
     * already documented by, or a new one named after it.
     *
     * A solution owns no pages of its own since the container swap, so
     * promotion needs a caderno to write into — and picking the first linked
     * one means an approval joins the documentation somebody is already
     * reading, instead of starting a second body of text beside it. Creating
     * one named after the solution when there is none is the same rule the
     * container migration used, so the two produce the same shape.
     */
    private function notebookFor(Solution $solution): Notebook
    {
        $existing = $solution->notebooks()->first();

        if ($existing) {
            return $existing;
        }

        $notebook = Notebook::create([
            'name' => $solution->name,
            'slug' => Notebook::uniqueSlug($solution->slug ?: $solution->name),
        ]);

        $solution->notebooks()->attach($notebook);

        return $notebook;
    }

    /** @return DocumentationPage|null the page written, or null when there was nothing to promote */
    public function handle(Submission $submission): ?DocumentationPage
    {
        $submission->loadMissing(['sections', 'solution', 'diagrams.media']);

        $solution = $submission->solution;

        if ($solution === null) {
            // Nothing to promote INTO. A submission that never named a catalog
            // solution has no home for its prose.
            return null;
        }

        $notebook = $this->notebookFor($solution);
        $title = "CATI — {$submission->name}";

        $page = $notebook->pages()->firstOrNew(['slug' => Str::slug($title)]);

        // The page has to exist before the drawing can be copied into its own
        // `docs` collection, and the Markdown has to name the media id the copy
        // produced — so the picture is settled first and the body built around
        // whatever it returns.
        if (! $page->exists) {
            // End of the caderno's TOP-LEVEL pages: `position` orders a page
            // among its siblings, so the promoted page joins the root list
            // rather than counting subpage positions it will never share.
            $page->fill(['title' => $title, 'documentation' => '', 'position' => $notebook->pages()->whereNull('parent_id')->max('position') + 1]);
            $notebook->pages()->save($page);
        }

        $body = $this->body($submission, $this->promotedImage($submission, $page));

        if (trim($body) === '') {
            return null;
        }

        $page->fill(['title' => $title, 'documentation' => $body]);
        $notebook->pages()->save($page);

        $submission->update(['promoted_at' => now()]);

        return $page;
    }

    /**
     * Copies the approved TO BE picture into the PAGE's own `docs` collection
     * and answers with the media id the Markdown should reference.
     *
     * Copied rather than referenced for two reasons: `/files/{id}` only serves
     * the `docs` collection (`MediaController::show()`), and documentation that
     * breaks when a submission is deleted is not documentation. The page owning
     * its own copy is the same contract every other embedded image has.
     *
     * Re-promotion reuses the copy instead of stacking a second one — the
     * collection is `singleFile()`-less by design (a page embeds many images),
     * so nothing else would have deduplicated it.
     */
    private function promotedImage(Submission $submission, DocumentationPage $page): ?int
    {
        $diagram = $submission->diagrams
            ->first(fn (SubmissionDiagram $d) => $d->kind === SubmissionDiagramKind::ToBe && $d->isFilled());

        $source = $diagram?->picture();

        if ($source === null || ! is_file($source->getPath())) {
            return null;
        }

        $existing = $page->getMedia(Documentable::DOCS_COLLECTION)
            ->first(fn ($media) => ($media->getCustomProperty('cati_diagram') ?? null) === $diagram->id);

        if ($existing !== null) {
            return $existing->id;
        }

        // Tagged so a re-promotion finds its own copy instead of stacking a
        // second one. `Model::save()` answers with a bool, so the id comes off
        // the media itself, not off the end of the chain.
        $copy = $source->copy($page, Documentable::DOCS_COLLECTION);
        $copy->setCustomProperty('cati_diagram', $diagram->id);
        $copy->save();

        return $copy->id;
    }

    /**
     * The page's Markdown: what the committee decided, then the sections that
     * describe how the thing works.
     */
    private function body(Submission $submission, ?int $imageMediaId = null): string
    {
        $parts = [];

        $decided = $submission->decided_at?->format('d/m/Y');
        $header = array_filter([
            $decided ? "**Deliberado em** {$decided}" : null,
            "**Situação** {$submission->status->label()}",
            $submission->ticket_reference ? "**Chamado** {$submission->ticket_reference}" : null,
        ]);

        $parts[] = implode(' · ', $header);

        if (filled($submission->decision)) {
            $parts[] = "> {$submission->decision}";
        }

        if ($submission->openConditions() !== []) {
            $conditions = collect($submission->openConditions())
                ->map(fn (array $condition) => "- [ ] {$condition['text']}")
                ->implode("\n");
            $parts[] = "## Ressalvas do comitê\n\n{$conditions}";
        }

        if ($imageMediaId !== null) {
            // Referenced the way every other documentation image is, so the
            // renderer and the public magic-link view need no special case.
            $parts[] = "## Arquitetura aprovada (TO BE)\n\n"
                . '<figure><img src="/files/' . $imageMediaId . '" alt="Arquitetura TO BE"></figure>';
        }

        $rows = $submission->sections->keyBy(fn ($section) => $section->key->value);

        foreach (self::PROMOTED as $key) {
            // The FULL text, never the condensed slide version: this is
            // documentation, and the argument is the point of it.
            $content = trim((string) $rows->get($key->value)?->content);

            if ($content !== '') {
                $parts[] = "## {$key->label()}\n\n{$content}";
            }
        }

        return implode("\n\n", $parts);
    }
}
