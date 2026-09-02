<?php

namespace App\Support\Digibee;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;

/**
 * Turns one synced documentation page into a ConnectorCard.
 *
 * A Digibee connector page is Markdown with the parameter reference written as
 * raw HTML `<table>`s (GitBook's wide-table syntax), grouped under `###`
 * headings — Connection, Authentication, Timeouts, Error handling. So the
 * distillation is: read the headings from the Markdown, read the rows with
 * DOMDocument, and keep only the tables that are actually parameter tables.
 *
 * **Header-driven, not position-driven.** The column order is not identical
 * across pages (some tables have no "Visible when" at all), so each column is
 * located by its header text rather than by index. A table whose header has no
 * "Parameter" column is not a parameter table — the pages also carry tables of
 * response codes, of supported operations, of card links — and is skipped
 * rather than mangled into rows of nonsense.
 */
class ConnectorCardBuilder
{
    /** Descriptions get long and prose-y; a card is a reference, not the page. */
    private const MAX_DESCRIPTION = 220;

    private const MAX_SUMMARY = 400;

    /**
     * Real line breaks only — deliberately NOT `\R`.
     *
     * `\R` outside UTF mode is a BYTE class that includes `0x85` (NEL), and
     * `0x85` is the third byte of `✅` (E2 9C 85) — the character Digibee prints
     * in the "Supports DB" column of every parameter table. So `preg_split('/\R/')`
     * tore each table into fragments at the first checkmark, and only the
     * fragment that still carried the opening `<table` was ever parsed: REST V2
     * came out with one parameter instead of twenty, silently. Anything walking
     * this corpus line by line has to spell the break out.
     */
    private const LINE_BREAK = '/\r\n|\r|\n/';

    private const PARAGRAPH_BREAK = '/(?:\r\n|\r|\n){2,}/';

    public function build(string $connector, DigibeeDocPage $page, string $markdown): ConnectorCard
    {
        return new ConnectorCard(
            connector: $connector,
            title: $this->title($markdown) ?: $page->title,
            url: $page->url,
            summary: $this->summary($markdown, $page),
            groups: $this->groups($markdown),
        );
    }

    private function title(string $markdown): string
    {
        return preg_match('/^#\s+(.+)$/m', $markdown, $m) === 1 ? trim($m[1]) : '';
    }

    /**
     * The page's own opening prose, falling back to the index's one-liner.
     *
     * Both are stripped of Markdown emphasis and links: a card is read by a
     * model that must not follow a relative `/documentation/...` href, and a
     * half-rendered `[Double Braces](/documentation/…)` is three tokens of
     * punctuation for no information.
     */
    private function summary(string $markdown, DigibeeDocPage $page): string
    {
        $body = preg_replace('/^>.*$/m', '', $markdown) ?? $markdown;          // the "available as Markdown" banner
        $body = preg_replace('/^#\s+.+$/m', '', $body) ?? $body;               // the title
        $body = preg_replace('/^##+\s*\*{0,2}Overview\*{0,2}\s*$/mi', '', $body) ?? $body;

        foreach (preg_split(self::PARAGRAPH_BREAK, trim($body)) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '' || str_starts_with($paragraph, '#') || str_starts_with($paragraph, '<')
                || str_starts_with($paragraph, '{%') || str_starts_with($paragraph, '*')) {
                continue;
            }

            return Str::limit($this->plain($paragraph), self::MAX_SUMMARY);
        }

        return Str::limit($this->plain($page->description), self::MAX_SUMMARY);
    }

    /**
     * The parameter reference, each table under the heading it sits below.
     *
     * The corpus writes this THREE different ways, and a card built for only
     * one of them silently loses whole connectors — the first pass here
     * understood HTML tables alone and produced no card at all for For Each,
     * JSON Generator, File Writer or JWT, four of the most used components in
     * our own pipelines:
     *
     * - an HTML `<table>` (GitBook's wide-table syntax, the current style);
     * - a Markdown pipe table (the older style, same columns);
     * - a bullet list of `* **Name:** description` (the oldest, no columns at
     *   all) — only read when the page has neither table, and only above three
     *   bullets, so a two-item list of subpipelines is not mistaken for a
     *   parameter reference.
     *
     * The heading is carried because it is real information — "Authentication"
     * vs "Timeouts" tells the model which parameters it can ignore for a plain
     * GET — and because a flat list of forty parameters reads as noise.
     *
     * @return list<array{name: string, parameters: list<array<string, string>>}>
     */
    private function groups(string $markdown): array
    {
        $groups = [];
        $heading = 'Parâmetros';
        // The level of the heading that opened the parameter section, or null
        // while outside one. A page opens it at `##` ("## **Parameters**") or
        // at `###` ("### Parameters"), and the section ends at the next heading
        // of the same level or shallower — which is what stops "## Syntax
        // options" and its tables of examples being read as parameters.
        $level = null;
        $pipe = [];

        foreach ([...preg_split(self::LINE_BREAK, $markdown) ?: [], '#'] as $line) {
            $isPipe = str_starts_with(ltrim($line), '|');

            if ($pipe !== [] && ! $isPipe) {
                $groups = $this->push($groups, $heading, $this->pipeRows($pipe));
                $pipe = [];
            }

            if (preg_match('/^(#{2,4})\s+(.+?)\s*$/', $line, $m) === 1) {
                $depth = strlen($m[1]);
                $name = trim(str_replace('*', '', $m[2]));
                $opens = Str::contains(Str::lower($name), ['parameter', 'parâmetro', 'parametro']);

                if ($opens) {
                    $level = $depth;
                } elseif ($level !== null && $depth <= $level) {
                    $level = null;
                }

                $heading = $name;

                continue;
            }

            if ($level === null) {
                continue;
            }

            if ($isPipe) {
                $pipe[] = $line;

                continue;
            }

            if (str_contains($line, '<table')) {
                $groups = $this->push($groups, $heading, $this->htmlRows($line));
            }
        }

        return $this->merge($groups === [] ? $this->bulletGroups($markdown) : $groups);
    }

    /**
     * @param  list<array{name: string, parameters: list<array<string, string>>}>  $groups
     * @param  list<array<string, string>>  $parameters
     * @return list<array{name: string, parameters: list<array<string, string>>}>
     */
    private function push(array $groups, string $heading, array $parameters): array
    {
        if ($parameters !== []) {
            $groups[] = ['name' => $heading, 'parameters' => $parameters];
        }

        return $groups;
    }

    /**
     * Two tables under one heading — a page occasionally splits a group in two,
     * and the HTML style repeats the heading for each — become one group, so
     * the card never prints the same heading twice.
     *
     * @param  list<array{name: string, parameters: list<array<string, string>>}>  $groups
     * @return list<array{name: string, parameters: list<array<string, string>>}>
     */
    private function merge(array $groups): array
    {
        $merged = [];

        foreach ($groups as $group) {
            $key = Str::lower($group['name']);

            $merged[$key] = [
                'name'       => $merged[$key]['name'] ?? $group['name'],
                'parameters' => [...($merged[$key]['parameters'] ?? []), ...$group['parameters']],
            ];
        }

        return array_values($merged);
    }

    /**
     * The oldest page style: no table, just `* **Operation:** the operation to
     * be performed`. Read only as a LAST resort and only from the page lead,
     * stopping at the sections that are about runtime rather than
     * configuration — a "Messages flow" example is a bullet list too.
     *
     * @return list<array{name: string, parameters: list<array<string, string>>}>
     */
    private function bulletGroups(string $markdown): array
    {
        $parameters = [];

        foreach (preg_split(self::LINE_BREAK, $markdown) ?: [] as $line) {
            if (preg_match('/^#{1,4}\s+(.+)$/', $line, $m) === 1
                && Str::contains(Str::lower(str_replace('*', '', $m[1])), ['messages flow', 'agent instructions', 'querying this'])) {
                break;
            }

            if (preg_match('/^[*-]\s+\*\*(?<name>[^*]+?)[:：]?\*\*[:：]?\s*(?<description>.*)$/u', $line, $bullet) !== 1) {
                continue;
            }

            $parameters[] = [
                'name'         => $this->plain($bullet['name']),
                'type'         => '',
                'doubleBraces' => '',
                'default'      => '',
                'visibleWhen'  => '',
                'description'  => Str::limit($this->plain($bullet['description']), self::MAX_DESCRIPTION),
            ];
        }

        return count($parameters) >= 3 ? [['name' => 'Parâmetros', 'parameters' => $parameters]] : [];
    }

    /**
     * A Markdown pipe table's rows, located by the same header names as the
     * HTML one — the two styles document the same columns, so the column
     * lookup is shared and only the tokenizer differs.
     *
     * @param  list<string>  $lines
     * @return list<array<string, string>>
     */
    private function pipeRows(array $lines): array
    {
        $rows = array_values(array_filter(
            array_map(fn (string $line) => $this->pipeCells($line), $lines),
            // The `| --- | --- |` separator carries no data, and a row shorter
            // than two cells is a stray pipe in prose.
            fn (array $cells) => count($cells) >= 2 && ! preg_match('/^:?-{2,}:?$/', $cells[0]),
        ));

        if (count($rows) < 2) {
            return [];
        }

        $headers = array_map(fn (string $cell) => Str::lower($this->plain($cell)), array_shift($rows));
        $columns = $this->columns($headers);

        if ($columns['name'] === null) {
            return [];
        }

        $parameters = [];

        foreach ($rows as $cells) {
            $parameter = $this->parameter(
                fn (?int $index) => $index === null ? '' : $this->plain($cells[$index] ?? ''),
                $columns,
            );

            if ($parameter !== null) {
                $parameters[] = $parameter;
            }
        }

        return $parameters;
    }

    /** @return list<string> */
    private function pipeCells(string $line): array
    {
        $trimmed = trim(trim($line), '|');

        return array_map('trim', explode('|', $trimmed));
    }

    /**
     * One HTML table's rows, keyed by what its header calls each column.
     *
     * @return list<array<string, string>>
     */
    private function htmlRows(string $html): array
    {
        $document = new DOMDocument;
        // GitBook tables carry `&#x3C;` and friends; libxml would otherwise
        // warn on every one of them and on the HTML5 attributes it does not
        // know (`data-header-sticky`).
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $headers = array_map(
            fn (DOMNode $cell) => Str::lower(trim($cell->textContent)),
            iterator_to_array($xpath->query('//thead//th') ?: []),
        );

        $columns = $this->columns($headers);

        // No "Parameter" column: this is a table of something else (response
        // codes, supported operations, a card grid). Leave it alone.
        if ($columns['name'] === null) {
            return [];
        }

        $parameters = [];

        foreach ($xpath->query('//tbody/tr') ?: [] as $row) {
            $cells = iterator_to_array($row->getElementsByTagName('td'));
            $parameter = $this->parameter(fn (?int $index) => $this->cell($cells, $index), $columns);

            if ($parameter !== null) {
                $parameters[] = $parameter;
            }
        }

        return $parameters;
    }

    /**
     * One row, whichever tokenizer produced its cells.
     *
     * @param  callable(?int): string  $cell
     * @param  array<string, int|null>  $columns
     * @return array<string, string>|null
     */
    private function parameter(callable $cell, array $columns): ?array
    {
        $name = $cell($columns['name']);

        if ($name === '') {
            return null;
        }

        return [
            // The pipe-table style marks Double Braces support inline, as
            // "**JSON** `(DB)`", where the HTML style gives it a column. Both
            // end up in the same field, so the card reads the same either way.
            'name'         => trim(str_replace('(DB)', '', $name)),
            'type'         => $cell($columns['type']),
            'doubleBraces' => $this->supportsDoubleBraces($cell($columns['doubleBraces']) ?: (str_contains($name, '(DB)') ? '✅' : '')),
            'default'      => $this->normalizeDefault($cell($columns['default'])),
            'visibleWhen'  => $this->normalizeCondition($cell($columns['visibleWhen'])),
            'description'  => Str::limit($cell($columns['description']), self::MAX_DESCRIPTION),
        ];
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, int|null>
     */
    private function columns(array $headers): array
    {
        return [
            'name'         => $this->columnIndex($headers, ['parameter', 'parâmetro', 'parametro']),
            'description'  => $this->columnIndex($headers, ['description', 'descrição']),
            'type'         => $this->columnIndex($headers, ['data type', 'type', 'tipo']),
            'doubleBraces' => $this->columnIndex($headers, ['supports db', 'double braces']),
            'default'      => $this->columnIndex($headers, ['default', 'padrão']),
            'visibleWhen'  => $this->columnIndex($headers, ['visible when', 'visível quando']),
        ];
    }

    /** @param list<string> $headers */
    private function columnIndex(array $headers, array $candidates): ?int
    {
        foreach ($headers as $index => $header) {
            foreach ($candidates as $candidate) {
                if ($header === $candidate || str_contains($header, $candidate)) {
                    return $index;
                }
            }
        }

        return null;
    }

    /** @param list<DOMElement> $cells */
    private function cell(array $cells, ?int $index): string
    {
        if ($index === null || ! isset($cells[$index])) {
            return '';
        }

        return $this->plain($cells[$index]->textContent);
    }

    /**
     * The docs mark this column with a ✅/❌, sometimes qualified
     * ("✅ (values only)"). The qualification is worth nothing to a model
     * writing JSON, and "yes"/"no" is one token instead of an emoji whose
     * meaning has to be inferred.
     */
    private function supportsDoubleBraces(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return str_contains($value, '✅') ? 'yes' : 'no';
    }

    /**
     * The docs spell "always visible" as an em dash, and "só quando —" is worse
     * than saying nothing at all.
     */
    private function normalizeCondition(string $value): string
    {
        return $this->isBlankMarker($value) ? '' : Str::limit($value, 90);
    }

    private function isBlankMarker(string $value): bool
    {
        return in_array(Str::lower(trim($value)), ['n/a', 'na', '-', '—', '–', ''], true);
    }

    /**
     * "N/A" is how the docs spell "no default", and repeating it on two thirds
     * of the parameters is two tokens each saying nothing.
     */
    private function normalizeDefault(string $value): string
    {
        return $this->isBlankMarker($value) ? '' : Str::limit($value, 80);
    }

    /** Markdown emphasis, links and entities out; one line in. */
    private function plain(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;
        $text = str_replace(['**', '`'], '', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
