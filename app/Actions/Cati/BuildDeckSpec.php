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
    public function __construct(private readonly MarkdownToBlocks $markdown) {}

    /** @return array<string, mixed> */
    public function handle(Submission $submission): array
    {
        $submission->loadMissing(['sections', 'solution', 'requester', 'solution.integrations.media']);

        $rows = $submission->sections->keyBy(fn ($section) => $section->key->value);

        $slides = [$this->cover($submission)];

        foreach (SubmissionSectionKey::cases() as $key) {
            $content = trim((string) $rows->get($key->value)?->content);

            // An empty optional section becomes no slide at all — an empty
            // slide in front of the committee is worse than a shorter deck.
            // A mandatory one still gets its slide, marked, for the same
            // reason the ticket prints "[não preenchido]" instead of a gap.
            if ($content === '' && ! $key->mandatory()) {
                continue;
            }

            $slides[] = [
                'layout' => 'content',
                'title'  => $key->label(),
                'blocks' => $content === ''
                    ? [['type' => 'paragraph', 'text' => '[não preenchido]', 'level' => 0]]
                    : $this->markdown->convert($content),
            ];

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
