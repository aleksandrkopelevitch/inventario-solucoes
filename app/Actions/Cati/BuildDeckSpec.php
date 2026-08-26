<?php

namespace App\Actions\Cati;

use App\Enums\SubmissionDiagramKind;
use App\Enums\SubmissionSectionKey;
use App\Models\Diagram;
use App\Models\Submission;
use App\Support\Cati\MarkdownToBlocks;

/**
 * Turns a submission into a deck spec — the JSON the renderer typesets.
 *
 * Deterministic on purpose, and shipped before the model-driven pass: eleven
 * written sections already carry everything the deck says, so the mapping to
 * slides is mechanical. What a model adds later is CONDENSING prose into
 * something readable from six metres away, and that is a separate concern
 * from deciding which slide exists.
 *
 * Nothing here writes a file. The spec is validated (DeckSpecValidator) and
 * only then rendered — the model, when it arrives, will produce this same
 * structure and answer to the same validator.
 */
class BuildDeckSpec
{
    /**
     * Roughly what the body box holds: 5.91in of height at 16pt with normal
     * leading is about 22 lines, and 12.33in of width is about 110 characters
     * per line. Deliberately conservative — a slide that ends early reads fine,
     * a slide that overflows does not.
     */
    private const CHARS_PER_SLIDE = 1400;

    private const BLOCKS_PER_SLIDE = 12;

    public function __construct(private readonly MarkdownToBlocks $markdown) {}

    /** @return array<string, mixed> */
    public function handle(Submission $submission): array
    {
        $submission->loadMissing(['sections', 'solution', 'requester', 'solution.diagrams.media']);

        $rows = $submission->sections->keyBy(fn ($section) => $section->key->value);

        $slides = [$this->cover($submission)];

        foreach (SubmissionSectionKey::cases() as $key) {
            // slideText() falls back to the full section whenever there is no
            // condensed version or it was made from text that has since been
            // edited — a summary of something that no longer exists is worse
            // than a verbose slide.
            $content = trim((string) $rows->get($key->value)?->slideText());

            // An empty optional section becomes no slide at all — an empty
            // slide in front of the committee is worse than a shorter deck.
            // A mandatory one still gets its slide, marked, for the same
            // reason the ticket prints "[não preenchido]" instead of a gap.
            if ($content === '' && ! $key->mandatory()) {
                continue;
            }

            $blocks = $content === ''
                ? [['type' => 'paragraph', 'text' => '[não preenchido]', 'level' => 0]]
                : $this->markdown->convert($content);

            $slides = [...$slides, ...$this->paginate($key->label(), $blocks)];

            // The diagrams follow the architecture section, which is where the
            // committee expects them and where the hand-made decks put them.
            if ($key === SubmissionSectionKey::Architecture) {
                $slides = [...$slides, ...$this->diagrams($submission)];
            }
        }

        $slides[] = $this->closing($submission);

        return [
            'title'  => $submission->name,
            'slides' => array_values($slides),
        ];
    }

    /**
     * Splits one section's blocks across as many slides as they need.
     *
     * Two things force this, and both are silent failures otherwise:
     *
     * - **A slide holds ONE figure.** A section with two tables would lose the
     *   second — the renderer places one and drops the rest without a word.
     * - **A slide holds a finite amount of text.** The body box is 12.33 x 5.91
     *   inches at 16pt, which is roughly 22 lines; past that the text runs off
     *   the bottom and nobody notices until it is on a projector.
     *
     * Continuation slides carry the section's name with "(cont.)" so the
     * committee can see it is the same subject rather than a new one.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function paginate(string $title, array $blocks): array
    {
        $slides = [];
        $current = [];
        $characters = 0;

        $flush = function () use (&$slides, &$current, &$characters, $title) {
            if ($current === []) {
                return;
            }

            $slides[] = [
                'layout' => 'content',
                'title'  => $slides === [] ? $title : "{$title} (cont.)",
                'blocks' => $current,
            ];

            $current = [];
            $characters = 0;
        };

        foreach ($blocks as $block) {
            $isFigure = in_array($block['type'] ?? null, ['table', 'image'], true);

            if ($isFigure) {
                // A figure closes whatever text preceded it and takes the rest
                // of the slide to itself.
                $current[] = $block;
                $flush();

                continue;
            }

            $length = mb_strlen((string) ($block['text'] ?? ''));

            if ($current !== [] && ($characters + $length > self::CHARS_PER_SLIDE || count($current) >= self::BLOCKS_PER_SLIDE)) {
                $flush();
            }

            $current[] = $block;
            $characters += $length;
        }

        $flush();

        return $slides;
    }

    /**
     * The architecture slides.
     *
     * A submission's OWN drawings come first, in the committee's order: AS IS,
     * TO BE, then the two C4 views. They are what the committee is actually
     * deliberating on — a proposal is about what will change, and until Fase 3
     * there was nowhere to draw that, so the deck could only ever show how the
     * catalog looks today.
     *
     * A picture with a LINK, never native shapes. Autoshapes would make the
     * deck a second place the diagram can be edited — someone nudges a box
     * during the meeting and the deck and the inventory disagree, silently.
     * One editing surface, many views.
     *
     * The Solution's existing diagram canvases still follow, and they are
     * not redundant: they are the catalog's own record of what exists today,
     * at the level of one diagram each, while AS IS is the submitter's
     * summary of the same reality. A submission that drew an AS IS suppresses
     * them, because two answers to "how does it work today" on consecutive
     * slides is a question from the committee, not an answer.
     *
     * @return list<array<string, mixed>>
     */
    private function diagrams(Submission $submission): array
    {
        $submission->loadMissing(['diagrams.media', 'solution.diagrams']);

        $slides = [];
        $drewAsIs = false;

        foreach (SubmissionDiagramKind::cases() as $kind) {
            $diagram = $submission->diagrams->firstWhere(fn ($d) => $d->kind === $kind);
            $media = $diagram?->isFilled() ? $diagram->picture() : null;

            // A slot with nothing in it is skipped rather than printed empty:
            // a slide with a hole in it reads as a mistake, and the ticket's
            // checklist already reports which drawings are missing.
            if ($media === null || ! is_file($media->getPath())) {
                continue;
            }

            $drewAsIs = $drewAsIs || $kind === SubmissionDiagramKind::AsIs;

            $slides[] = [
                'layout' => 'content',
                'title'  => $kind->slideTitle(),
                'blocks' => [[
                    'type' => 'image',
                    'path' => $media->getPath(),
                    // Only a drawn kind has a canvas to go back to; an
                    // uploaded C4 came from a tool this app does not host.
                    'link' => $kind->isDrawn()
                        ? route('submissions.diagrams.edit', [$submission, $diagram])
                        : null,
                ]],
            ];
        }

        return [...$slides, ...$this->diagramSlides($submission, $drewAsIs)];
    }

    /**
     * One slide per diagram of the linked Solution that has a picture of
     * its canvas — the catalog's own view of what exists today.
     *
     * Suppressed once the submission drew its own AS IS: the two answer the
     * same question at different altitudes, and printing both invites the
     * committee to spot the difference instead of reading the proposal.
     *
     * @return list<array<string, mixed>>
     */
    private function diagramSlides(Submission $submission, bool $drewAsIs): array
    {
        $solution = $submission->solution;

        if ($solution === null || $drewAsIs) {
            return [];
        }

        $slides = [];

        foreach ($solution->diagrams as $diagram) {
            $media = $diagram->getFirstMedia(Diagram::DIAGRAM_COLLECTION);

            // No picture yet means nobody has saved that canvas since the
            // feature existed. Skipping beats a slide with a hole in it.
            if ($media === null || ! is_file($media->getPath())) {
                continue;
            }

            $slides[] = [
                'layout' => 'content',
                'title'  => "Arquitetura — {$diagram->name}",
                'blocks' => [[
                    'type' => 'image',
                    'path' => $media->getPath(),
                    'link' => route('diagrams.show', $diagram),
                ]],
            ];
        }

        return $slides;
    }

    /** @return array<string, mixed> */
    private function cover(Submission $submission): array
    {
        return [
            'layout'   => 'cover',
            'title'    => $submission->name,
            'subtitle' => trim(implode(' | ', array_filter([
                $submission->solution?->name,
                'Comitê de Arquitetura de TI',
            ]))),
            'footnote' => $submission->committee_date?->format('m/Y') ?? now()->format('m/Y'),
        ];
    }

    /** @return array<string, mixed> */
    private function closing(Submission $submission): array
    {
        return [
            'layout'   => 'closing',
            'title'    => 'Obrigado',
            'subtitle' => $submission->requester?->name ?? '',
        ];
    }
}
