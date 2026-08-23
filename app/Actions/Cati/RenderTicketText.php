<?php

namespace App\Actions\Cati;

use App\Enums\SubmissionSectionKey;
use App\Enums\SubmissionSectionState;
use App\Models\Submission;
use App\Models\SubmissionSection;
use App\Support\Cati\SubmissionRequirements;
use Illuminate\Support\Collection;

/**
 * Renders the text to paste into the Leo Resolve ticket — the seven form
 * sections, in the form's order, followed by its final checklist.
 *
 * The point of this class is that nobody retypes: the same record that
 * produces the document (and, from Fase 2, the deck) produces the ticket, so
 * the two can't drift after the first correction.
 *
 * The headings are printed exactly as the form words them
 * (SubmissionSectionKey::ticketHeading()) — it is what the committee reads.
 */
class RenderTicketText
{
    /**
     * The form's own checklist wording, by section.
     *
     * `architecture` is deliberately absent: its checklist item is "Diagramas
     * de arquitetura anexados (desenho da solução e C4 com mínimo C1/C2)",
     * which is a claim about ATTACHMENTS, not about the section being written.
     * It was emitted permanently unticked through Fases 1 and 2, because
     * ticking it from the section's state would have put a false compliance
     * claim in front of the committee.
     *
     * Since Fase 3 the submission carries the drawings themselves, so the item
     * is derived from what is actually there
     * (`SubmissionRequirements::diagramsComplete()`) — and it stays a claim
     * about attachments: it ticks when all four slots are filled, never
     * because the architecture section reads well.
     */
    private const CHECKLIST = [
        'summary'        => 'Resumo da proposta preenchido',
        'benefits_risks' => 'Benefícios e riscos detalhados',
        'legacy_impact'  => 'Impactos em integrações e legados descritos',
        'standards'      => 'Padrões adotados informados',
        'plan_costs'     => 'Plano de implementação e custos estimados incluídos',
        'alternatives'   => 'Alternativas avaliadas registradas',
    ];

    private const DIAGRAMS_ITEM = 'Diagramas de arquitetura anexados (desenho da solução e C4 com mínimo C1/C2)';

    public function handle(Submission $submission): string
    {
        $submission->loadMissing(['sections', 'diagrams.media']);

        $sections = $submission->sections->keyBy(fn ($section) => $section->key->value);

        $blocks = [];

        foreach (SubmissionSectionKey::ticketOrdered() as $key) {
            $section = $sections->get($key->value);
            $content = trim((string) $section?->content);

            $blocks[] = "### {$key->ticketOrder()}. {$key->ticketHeading()}\n\n"
                . ($content === '' ? '_[não preenchido]_' : $content);
        }

        return implode("\n\n***\n\n", $blocks)
            . "\n\n***\n\n"
            . $this->checklist($sections, SubmissionRequirements::diagramsComplete($submission));
    }

    /**
     * Ticked from what the record actually holds, so nobody ticks a box by
     * hand — a confirmed section is one a human signed off on, which is
     * exactly what the checklist is asserting.
     *
     * @param  Collection<string, SubmissionSection>  $sections
     */
    private function checklist($sections, bool $diagramsComplete): string
    {
        $lines = ['### Checklist Final', ''];

        foreach (self::CHECKLIST as $key => $label) {
            $section = $sections->get($key);
            $done = $section?->state === SubmissionSectionState::Confirmed && $section->isAnswered();

            $lines[] = '* [' . ($done ? 'x' : ' ') . '] ' . $label;

            // The diagrams item sits between the summary and the benefits on
            // the form, right after "Arquitetura de Solução".
            if ($key === 'summary') {
                $lines[] = '* [' . ($diagramsComplete ? 'x' : ' ') . '] ' . self::DIAGRAMS_ITEM;
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
