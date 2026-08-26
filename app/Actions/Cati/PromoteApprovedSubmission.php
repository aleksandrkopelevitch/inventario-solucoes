<?php

namespace App\Actions\Cati;

use App\Contracts\Documentable;
use App\Enums\SubmissionDiagramKind;
use App\Enums\SubmissionSectionKey;
use App\Models\ApprovedTopology;
use App\Models\DocumentationPage;
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
 * Three things move, and the third one is new since Fase 3.
 *
 * This docblock used to argue that promoting topology was unnecessary — "the
 * architect edits the inventory's own chain while preparing, so the topology is
 * already promoted the moment it is drawn". That was true while the diagrams
 * were pictures of the LIVE diagram canvas. Fase 3 gave a submission
 * drawings of its OWN, whose `afterChainMutation()` is deliberately empty, so
 * an approved TO BE reached nothing and the catalog drifted again.
 *
 * It still does not WRITE the topology: a submission's TO BE is a free graph
 * that may describe several diagrams or one that does not exist yet, and an
 * approval that guessed the target would overwrite real topology with a guess.
 * It records an `ApprovedTopology` instead — pending, visible, and closable by
 * a person either way (see that model, and `ApplyApprovedTopology`).
 *
 * Idempotent by `promoted_at`, and it never overwrites an existing page — a
 * second run updates the page it created rather than stacking duplicates.
 */
class PromoteApprovedSubmission
{
    /**
     * Sections whose prose belongs in the Solution's documentation. The
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

        $title = "CATI — {$submission->name}";

        $page = $solution->pages()->firstOrNew(['slug' => Str::slug($title)]);

        // The page has to exist before the drawing can be copied into its own
        // `docs` collection, and the Markdown has to name the media id the copy
        // produced — so the picture is settled first and the body built around
        // whatever it returns.
        if (! $page->exists) {
            // End of the solution's TOP-LEVEL pages: `position` orders a page
            // among its siblings, so the promoted page joins the root list
            // rather than counting subpage positions it will never share.
            $page->fill(['title' => $title, 'documentation' => '', 'position' => $solution->pages()->whereNull('parent_id')->max('position') + 1]);
            $solution->pages()->save($page);
        }

        $body = $this->body($submission, $this->promotedImage($submission, $page));

        if (trim($body) === '') {
            return null;
        }

        $page->fill(['title' => $title, 'documentation' => $body]);
        $solution->pages()->save($page);

        $this->recordApprovedTopology($submission, $solution);

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
     * Records what the committee approved, for the catalog to catch up with.
     *
     * A SNAPSHOT of the chain, not a pointer at the drawing: the submitter can
     * keep editing it afterwards, and a pending change that quietly became a
     * different drawing is worse than none. Idempotent by submission, so
     * re-promoting refreshes a still-pending row and never resurrects one
     * somebody already applied or dismissed.
     */
    private function recordApprovedTopology(Submission $submission, Solution $solution): void
    {
        $diagram = $submission->diagrams
            ->first(fn (SubmissionDiagram $d) => $d->kind === SubmissionDiagramKind::ToBe && $d->isFilled());

        if ($diagram === null) {
            return;
        }

        $record = ApprovedTopology::firstOrNew(['submission_id' => $submission->id]);

        if ($record->exists && ! $record->isPending()) {
            return;
        }

        $record->fill([
            'solution_id' => $solution->id,
            'chain'       => $diagram->chain,
            'viz_layout'  => $diagram->viz_layout,
            'approved_at' => $submission->decided_at ?? now(),
        ])->save();
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
