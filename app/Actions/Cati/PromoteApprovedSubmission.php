<?php

namespace App\Actions\Cati;

use App\Enums\SubmissionSectionKey;
use App\Models\DocumentationPage;
use App\Models\Submission;
use Illuminate\Support\Str;

/**
 * Pushes an approved submission into the catalog.
 *
 * This is the flywheel, and without it the module is a slide factory: what the
 * committee approved would live in a `.pptx` and the inventory would keep
 * drifting until the next submission has to reconstruct the AS-IS by hand.
 *
 * Note what it does NOT have to do. The plan originally called for promoting a
 * submission-owned TO-BE graph into the real topology — that requirement
 * disappeared when diagrams became pictures of the LIVE canvas: the architect
 * edits the inventory's own chain while preparing, so the topology is already
 * promoted the moment it is drawn. What is left to move is the prose and the
 * decision.
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
        $submission->loadMissing(['sections', 'solution']);

        $solution = $submission->solution;

        if ($solution === null) {
            // Nothing to promote INTO. A submission that never named a catalog
            // solution has no home for its prose.
            return null;
        }

        $body = $this->body($submission);

        if (trim($body) === '') {
            return null;
        }

        $title = "CATI — {$submission->name}";

        $page = $solution->pages()
            ->firstOrNew(['slug' => Str::slug($title)]);

        $page->fill([
            'title'         => $title,
            'documentation' => $body,
            'position'      => $page->exists ? $page->position : ($solution->pages()->max('position') + 1),
        ]);

        $solution->pages()->save($page);

        $submission->update(['promoted_at' => now()]);

        return $page;
    }

    /**
     * The page's Markdown: what the committee decided, then the sections that
     * describe how the thing works.
     */
    private function body(Submission $submission): string
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
