<?php

namespace App\Support\Documentation;

/**
 * A "comparação lado a lado" — the fourth SCENE type: OPTIONS (2–4) set
 * against CRITERIA (2–6), drawn as a matrix that fills in row by row.
 *
 * It is not the "antes → depois". That one compares two MOMENTS of the same
 * thing; this compares ALTERNATIVES — the options an analysis weighed ("Opção
 * A" × "Opção B"), two tools for the same job, the plans a vendor offers.
 *
 * Each cell is a short text plus a MARK — `good` (atende), `partial` (em
 * parte), `bad` (não atende) or none — and the mark is content, not
 * decoration: it is what the reader scans the column for. So an unknown mark
 * IS a validation error (unlike an unknown icon, which is dropped). At most one
 * option may be `highlight` — the one the page recommends.
 *
 * A criterion carries its values IN OPTION ORDER, one per option; a count that
 * does not match is refused, because a value in the wrong column is a wrong
 * answer that looks right.
 */
final class ComparisonScene
{
    public const TYPE = 'compare';

    public const MIN_OPTIONS = 2;

    public const MAX_OPTIONS = 4;

    public const MIN_CRITERIA = 2;

    public const MAX_CRITERIA = 6;

    public const MAX_NAME = 28;

    public const MAX_DETAIL = 60;

    public const MAX_CRITERION = 32;

    public const MAX_VALUE = 60;

    public const MAX_CAPTION = 120;

    /** The marks a cell may carry; '' is "no mark". */
    public const MARKS = ['good', 'partial', 'bad'];

    /**
     * @param  list<array{name: string, detail: string, icon: string, highlight: bool}>  $options
     * @param  list<array{name: string, icon: string, values: list<array{text: string, mark: string}>}>  $criteria
     */
    private function __construct(
        public readonly string $caption,
        public readonly array $options,
        public readonly array $criteria,
    ) {}

    /**
     * @param  array<mixed>  $payload
     * @return list<string> empty when the payload is a valid comparison
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

        $options = $payload['options'] ?? null;

        if (! is_array($options) || ! array_is_list($options)) {
            return [...$problems, 'O campo "options" é obrigatório e deve ser uma lista.'];
        }

        if (count($options) < self::MIN_OPTIONS || count($options) > self::MAX_OPTIONS) {
            $problems[] = 'A lista "options" deve ter entre ' . self::MIN_OPTIONS . ' e ' . self::MAX_OPTIONS . ' opções (tem ' . count($options) . ').';
        }

        $highlights = 0;

        foreach ($options as $i => $option) {
            $n = $i + 1;

            if (! is_array($option)) {
                $problems[] = "A opção {$n} deve ser um objeto.";

                continue;
            }

            $name = $option['name'] ?? null;

            if (! is_string($name) || trim($name) === '') {
                $problems[] = "A opção {$n} precisa de um \"name\".";
            } elseif (mb_strlen(trim($name)) > self::MAX_NAME) {
                $problems[] = "O \"name\" da opção {$n} passa de " . self::MAX_NAME . ' caracteres — encurte.';
            }

            $detail = $option['detail'] ?? '';

            if (! is_string($detail) || mb_strlen(trim($detail)) > self::MAX_DETAIL) {
                $problems[] = "O \"detail\" da opção {$n} deve ser um texto de até " . self::MAX_DETAIL . ' caracteres.';
            }

            if (($option['highlight'] ?? false) === true) {
                $highlights++;
            }
        }

        if ($highlights > 1) {
            $problems[] = 'No máximo UMA opção pode ter "highlight": true (há ' . $highlights . ').';
        }

        $criteria = $payload['criteria'] ?? null;

        if (! is_array($criteria) || ! array_is_list($criteria)) {
            return [...$problems, 'O campo "criteria" é obrigatório e deve ser uma lista.'];
        }

        if (count($criteria) < self::MIN_CRITERIA || count($criteria) > self::MAX_CRITERIA) {
            $problems[] = 'A lista "criteria" deve ter entre ' . self::MIN_CRITERIA . ' e ' . self::MAX_CRITERIA . ' critérios (tem ' . count($criteria) . ').';
        }

        foreach ($criteria as $i => $criterion) {
            $n = $i + 1;

            if (! is_array($criterion)) {
                $problems[] = "O critério {$n} deve ser um objeto.";

                continue;
            }

            $name = $criterion['name'] ?? null;

            if (! is_string($name) || trim($name) === '') {
                $problems[] = "O critério {$n} precisa de um \"name\".";
            } elseif (mb_strlen(trim($name)) > self::MAX_CRITERION) {
                $problems[] = "O \"name\" do critério {$n} passa de " . self::MAX_CRITERION . ' caracteres — encurte.';
            }

            $where = is_string($name) && trim($name) !== '' ? '"' . trim($name) . '"' : "{$n}";
            $values = $criterion['values'] ?? null;

            if (! is_array($values) || ! array_is_list($values)) {
                $problems[] = "O critério {$where} precisa de \"values\", uma lista com um valor por opção.";

                continue;
            }

            if (count($values) !== count($options)) {
                $problems[] = "O critério {$where} tem " . count($values) . ' valores, mas há ' . count($options) . ' opções — um valor por opção, na mesma ordem.';
            }

            foreach ($values as $k => $value) {
                $column = $k + 1;

                if (! is_array($value)) {
                    $problems[] = "O valor {$column} do critério {$where} deve ser um objeto com \"text\" e \"mark\".";

                    continue;
                }

                $text = $value['text'] ?? '';
                $mark = $value['mark'] ?? '';

                if (! is_string($text) || mb_strlen(trim($text)) > self::MAX_VALUE) {
                    $problems[] = "O \"text\" do valor {$column} do critério {$where} deve ter até " . self::MAX_VALUE . ' caracteres.';
                }

                if (! in_array($mark, ['', ...self::MARKS], true)) {
                    $problems[] = "O \"mark\" do valor {$column} do critério {$where} deve ser \"good\", \"partial\", \"bad\" ou vazio.";
                } elseif (is_string($text) && trim($text) === '' && $mark === '') {
                    $problems[] = "O valor {$column} do critério {$where} está vazio — escreva um texto, uma marca ou os dois.";
                }
            }
        }

        return $problems;
    }

    /** @param  array<mixed>  $payload  a payload `validate()` accepted */
    public static function fromArray(array $payload): self
    {
        return new self(
            trim((string) ($payload['caption'] ?? '')),
            array_map(fn (array $option): array => [
                'name'      => trim((string) $option['name']),
                'detail'    => trim((string) ($option['detail'] ?? '')),
                'icon'      => SceneIcons::sanitize($option['icon'] ?? null),
                'highlight' => ($option['highlight'] ?? false) === true,
            ], $payload['options']),
            array_map(fn (array $criterion): array => [
                'name'   => trim((string) $criterion['name']),
                'icon'   => SceneIcons::sanitize($criterion['icon'] ?? null),
                'values' => array_map(fn (array $value): array => [
                    'text' => trim((string) ($value['text'] ?? '')),
                    'mark' => (string) ($value['mark'] ?? ''),
                ], $criterion['values']),
            ], $payload['criteria']),
        );
    }

    /** @return array{type: string, caption: string, options: list<array{name: string, detail: string, icon: string, highlight: bool}>, criteria: list<array{name: string, icon: string, values: list<array{text: string, mark: string}>}>} */
    public function toArray(): array
    {
        return [
            'type'     => self::TYPE,
            'caption'  => $this->caption,
            'options'  => $this->options,
            'criteria' => $this->criteria,
        ];
    }
}
