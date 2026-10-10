<?php

namespace App\Support\Documentation;

/**
 * A "fluxo em etapas" — the first SCENE type: an ordered run of steps drawn as
 * an animated figure inside a documentation page.
 *
 * A scene is what a model may propose and what the page stores, and on purpose
 * it carries no geometry at all. The model picks the CONTENT (which steps, in
 * what order, which one matters most) and `resources/js/modules/docs-scene.js`
 * decides every coordinate, colour and timing. Placement is exactly where a
 * fast model goes wrong, and keeping it out of the contract is what lets a
 * Flash-class model produce something that never comes out crooked or off-brand.
 *
 * The limits are part of the contract the prompt promises. Past eight steps a
 * flow stops being a figure and becomes a list somebody should have written as
 * one; a label is short because it is drawn inside a fixed-width card.
 *
 * Pure — nothing here touches the database, so the whole contract is testable
 * without one. The dialect form (`{% scene type="steps" %}`) is read by
 * GitbookRenderer and written by docs-markdown.js; this class is the shape both
 * the model's answer and the stored block agree on.
 */
final class StepScene
{
    public const TYPE = 'steps';

    public const MIN_STEPS = 2;

    public const MAX_STEPS = 8;

    public const MAX_TITLE = 40;

    public const MAX_DETAIL = 140;

    public const MAX_CAPTION = 120;

    /**
     * @param  list<array{title: string, detail: string, highlight: bool}>  $steps
     */
    private function __construct(
        public readonly string $caption,
        public readonly array $steps,
    ) {}

    /**
     * Everything wrong with a payload, in PT-BR, as sentences a model can act
     * on in the repair round — each names the step it is about.
     *
     * @param  array<mixed>  $payload
     * @return list<string> empty when the payload is a valid scene
     */
    public static function validate(array $payload): array
    {
        $problems = [];

        $caption = $payload['caption'] ?? '';

        if (! is_string($caption)) {
            $problems[] = 'O campo "caption" deve ser um texto.';
        } elseif (mb_strlen(trim($caption)) > self::MAX_CAPTION) {
            $problems[] = 'O campo "caption" passa de ' . self::MAX_CAPTION . ' caracteres.';
        }

        $steps = $payload['steps'] ?? null;

        if (! is_array($steps) || ! array_is_list($steps)) {
            return [...$problems, 'O campo "steps" é obrigatório e deve ser uma lista.'];
        }

        if (count($steps) < self::MIN_STEPS || count($steps) > self::MAX_STEPS) {
            $problems[] = 'A lista "steps" deve ter entre ' . self::MIN_STEPS . ' e ' . self::MAX_STEPS . ' etapas (tem ' . count($steps) . ').';
        }

        $highlights = 0;

        foreach ($steps as $i => $step) {
            $n = $i + 1;

            if (! is_array($step)) {
                $problems[] = "A etapa {$n} deve ser um objeto.";

                continue;
            }

            $title = $step['title'] ?? null;

            if (! is_string($title) || trim($title) === '') {
                $problems[] = "A etapa {$n} precisa de um \"title\".";
            } elseif (mb_strlen(trim($title)) > self::MAX_TITLE) {
                $problems[] = "O \"title\" da etapa {$n} passa de " . self::MAX_TITLE . ' caracteres — encurte para caber no card.';
            }

            $detail = $step['detail'] ?? '';

            if (! is_string($detail)) {
                $problems[] = "O \"detail\" da etapa {$n} deve ser um texto.";
            } elseif (mb_strlen(trim($detail)) > self::MAX_DETAIL) {
                $problems[] = "O \"detail\" da etapa {$n} passa de " . self::MAX_DETAIL . ' caracteres.';
            }

            if (isset($step['highlight']) && ! is_bool($step['highlight'])) {
                $problems[] = "O \"highlight\" da etapa {$n} deve ser true ou false.";
            } elseif (($step['highlight'] ?? false) === true) {
                $highlights++;
            }
        }

        if ($highlights > 1) {
            $problems[] = 'No máximo UMA etapa pode ter "highlight": true (há ' . $highlights . ').';
        }

        return $problems;
    }

    /** @param  array<mixed>  $payload  a payload `validate()` accepted */
    public static function fromArray(array $payload): self
    {
        return new self(
            trim((string) ($payload['caption'] ?? '')),
            array_map(fn (array $step): array => [
                'title'     => trim((string) $step['title']),
                'detail'    => trim((string) ($step['detail'] ?? '')),
                'highlight' => ($step['highlight'] ?? false) === true,
            ], $payload['steps']),
        );
    }

    /** @return array{type: string, caption: string, steps: list<array{title: string, detail: string, highlight: bool}>} */
    public function toArray(): array
    {
        return ['type' => self::TYPE, 'caption' => $this->caption, 'steps' => $this->steps];
    }
}
