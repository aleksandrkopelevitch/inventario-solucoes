<?php

namespace App\Support\Digibee;

/**
 * One connector, distilled from its documentation page into something a prompt
 * can afford: the summary, and every configuration parameter with its type,
 * default, whether it accepts Double Braces, and the condition that makes it
 * appear at all.
 *
 * **A card is not the page.** The REST V2 page is 25 KB; its card is ~2 KB,
 * because the prose around the parameter tables (how to import a cURL, where
 * the Import button is) is about the CANVAS, and the model is writing JSON. The
 * tables are the part that transfers.
 *
 * **It names UI labels, and says so.** Digibee documents "Stop On Client
 * Error", while the flowSpec key is `stopOnClientError` — the docs simply never
 * print the JSON key. That gap is what the tenant vocabulary
 * (App\Support\Digibee\TenantVocabulary) fills from our own pipelines, and why
 * the two corpora are injected together rather than either one alone.
 */
final readonly class ConnectorCard
{
    /**
     * @param  list<array{name: string, parameters: list<array{name: string, type: string, doubleBraces: string, default: string, visibleWhen: string, description: string}>}>  $groups
     */
    public function __construct(
        public string $connector,
        public string $title,
        public string $url,
        public string $summary,
        public array $groups,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            connector: (string) $data['connector'],
            title: (string) $data['title'],
            url: (string) $data['url'],
            summary: (string) ($data['summary'] ?? ''),
            groups: array_values($data['groups'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'connector' => $this->connector,
            'title'     => $this->title,
            'url'       => $this->url,
            'summary'   => $this->summary,
            'groups'    => $this->groups,
        ];
    }

    public function parameterCount(): int
    {
        return array_sum(array_map(fn (array $group) => count($group['parameters']), $this->groups));
    }

    /**
     * The card as the prompt sees it.
     *
     * Written in the vocabulary the rest of the flowSpec prompt uses (PT-BR
     * labels around English identifiers), and every parameter on ONE line: a
     * table costs tokens for alignment the model does not read, and a
     * multi-line entry per parameter buries the list a card exists to show.
     */
    public function toPrompt(): string
    {
        $lines = ["## {$this->title} (`{$this->connector}`)"];

        if ($this->summary !== '') {
            $lines[] = $this->summary;
        }

        foreach ($this->groups as $group) {
            if ($group['parameters'] === []) {
                continue;
            }

            $lines[] = "### {$group['name']}";

            foreach ($group['parameters'] as $parameter) {
                $facts = array_filter([
                    $parameter['type'],
                    $parameter['default'] !== '' ? "padrão: {$parameter['default']}" : '',
                    $parameter['doubleBraces'] === 'yes' ? 'aceita Double Braces' : '',
                    $parameter['visibleWhen'] !== '' ? "só quando {$parameter['visibleWhen']}" : '',
                ]);

                $line = "- **{$parameter['name']}**";
                $line .= $facts === [] ? '' : ' (' . implode('; ', $facts) . ')';
                $line .= $parameter['description'] === '' ? '' : " — {$parameter['description']}";

                $lines[] = $line;
            }
        }

        $lines[] = "(fonte: {$this->url})";

        return implode("\n", $lines);
    }
}
