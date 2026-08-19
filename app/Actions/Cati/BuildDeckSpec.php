<?php

namespace App\Actions\Cati;

use App\Enums\SubmissionSectionKey;
use App\Models\Integration;
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
        $submission->loadMissing(['sections', 'solution', 'requester', 'solution.integrations.media']);

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
     * One slide per integration the Solution takes part in that has a picture
     * of its canvas.
     *
     * The image is what the F3 canvas published on its last save, and it goes
     * in with a hyperlink back to that canvas. Native shapes would make the
     * deck a SECOND place the diagram can be edited — someone nudges a box
     * during the meeting and the deck and the inventory disagree, silently.
     * A picture plus a link keeps the canvas authoritative: one editing
     * surface, many views.
     *
     * @return list<array<string, mixed>>
     */
    private function diagrams(Submission $submission): array
    {
        $solution = $submission->solution;

        if ($solution === null) {
            return [];
        }

        $slides = [];

        foreach ($solution->integrations as $integration) {
            $media = $integration->getFirstMedia(Integration::DIAGRAM_COLLECTION);

            // No picture yet means nobody has saved that canvas since the
            // feature existed. Skipping beats a slide with a hole in it.
            if ($media === null || ! is_file($media->getPath())) {
                continue;
            }

            $slides[] = [
                'layout' => 'content',
                'title'  => "Arquitetura — {$integration->name}",
                'blocks' => [[
                    'type' => 'image',
                    'path' => $media->getPath(),
                    'link' => route('solutions.integrations.docs.edit', [$solution, $integration]),
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
