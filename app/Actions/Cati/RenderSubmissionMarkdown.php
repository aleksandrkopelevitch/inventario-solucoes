<?php

namespace App\Actions\Cati;

use App\Enums\SubmissionSectionKey;
use App\Enums\SubmissionSectionState;
use App\Models\Submission;

/**
 * Renders the whole submission as one Markdown document — the artifact that
 * actually drives a review.
 *
 * This is the deliverable Fase 1 exists to produce, and it is deliberately a
 * DOCUMENT rather than slides: a deck lets a claim through without an
 * argument, prose doesn't. It covers all eleven sections (the seven the ticket
 * takes plus the four only the deck asks for), so the same record already
 * holds everything Fase 2 needs to typeset.
 *
 * Two things it must never hide:
 *
 * - **A section the assistant drafted and nobody confirmed is marked, in the
 *   document itself.** A generated text that reads as human-signed is the
 *   failure mode of this whole module: the committee's job gets harder, not
 *   easier, if reviewers trust prose no one wrote.
 * - **Where the material came from**, listed at the end. A reviewer trusts a
 *   generated document exactly as far as they can check it.
 */
class RenderSubmissionMarkdown
{
    public function handle(Submission $submission): string
    {
        $submission->loadMissing(['sections', 'sources', 'solution', 'requester']);

        return implode("\n\n", array_filter([
            "# {$submission->name}",
            $this->metadata($submission),
            $this->sections($submission),
            $this->sources($submission),
        ])) . "\n";
    }

    private function metadata(Submission $submission): string
    {
        $rows = array_filter([
            'Solução'     => $submission->solution?->name,
            'Solicitante' => $submission->requester?->name,
            'Situação'    => $submission->status->label(),
            'Comitê'      => $submission->committee_date?->format('d/m/Y'),
            'Chamado'     => $submission->ticket_reference,
        ]);

        $lines = ['| | |', '|---|---|'];

        foreach ($rows as $label => $value) {
            $lines[] = "| **{$label}** | {$value} |";
        }

        return implode("\n", $lines);
    }

    private function sections(Submission $submission): string
    {
        $rows = $submission->sections->keyBy(fn ($section) => $section->key->value);
        $blocks = [];

        foreach (SubmissionSectionKey::cases() as $key) {
            $section = $rows->get($key->value);
            $content = trim((string) $section?->content);

            $heading = "## {$key->label()}";

            if ($content === '') {
                $blocks[] = $heading . "\n\n_Não preenchido._";

                continue;
            }

            if ($section?->state === SubmissionSectionState::Drafted) {
                $heading .= ' _(rascunho da IA, não confirmado)_';
            }

            $blocks[] = $heading . "\n\n" . $content;
        }

        return implode("\n\n", $blocks);
    }

    /**
     * The provenance appendix. Flags anything that looked like a credential —
     * the scanner never removes it, so the document is where the author is
     * reminded it is still in there.
     */
    private function sources(Submission $submission): string
    {
        if ($submission->sources->isEmpty()) {
            return '';
        }

        $lines = ['## Material consultado', ''];

        foreach ($submission->sources as $source) {
            $line = "- **{$source->label}** — " . $source->extraction_state->label();

            if ($source->url !== null) {
                $line .= " ({$source->url})";
            }

            if ($source->hasSensitiveFindings()) {
                $types = implode(', ', array_column($source->sensitive_findings, 'type'));
                $line .= " — ⚠ possível credencial no conteúdo: {$types}";
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
