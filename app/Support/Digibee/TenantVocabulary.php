<?php

namespace App\Support\Digibee;

use App\Actions\Digibee\IndexPipelineVocabulary;
use Illuminate\Support\Str;

/**
 * The read side of `database/data/digibee_tenant_vocabulary.json`: how Leo
 * Madeiras' own pipelines actually spell a connector.
 *
 * This exists because the documentation cannot answer the question the
 * generator has. Measured against the real export (176 pipelines, 2026-09-02):
 * the REST V2 page documents a parameter called **Verb**, and every one of the
 * 129 real `rest-connector-v2` steps writes it as `operation`; **Send A File**
 * is `sendBinaryFile`. A model given only the docs writes `"verb"`, the
 * validator has no rule about params and passes it, and the failure surfaces
 * when somebody pastes the result into the canvas.
 *
 * So a card and this vocabulary are injected as a PAIR — the card says what a
 * parameter MEANS, this says what it is CALLED and what its value looks like.
 */
class TenantVocabulary
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /** How many param keys one connector contributes to a prompt, most used first. */
    private const MAX_KEYS = 28;

    public function isEmpty(): bool
    {
        return ($this->data()['connectors'] ?? []) === [];
    }

    public function pipelines(): int
    {
        return (int) ($this->data()['pipelines'] ?? 0);
    }

    /** @return array{uses: int, params: array<string, int>, samples: list<array<string, mixed>>}|null */
    public function forConnector(string $connector): ?array
    {
        return $this->data()['connectors'][$connector] ?? null;
    }

    /** @return list<string> */
    public function globals(): array
    {
        return $this->data()['globals'] ?? [];
    }

    /** @return list<string> */
    public function accounts(): array
    {
        return $this->data()['accounts'] ?? [];
    }

    /**
     * The section a prompt carries for the connectors in play.
     *
     * Only connectors we have actually USED appear: a connector with no real
     * usage has nothing to teach here, and saying so would be inventing a shape
     * — which is the failure this whole artifact exists to prevent.
     *
     * @param  list<string>  $connectors
     */
    public function toPrompt(array $connectors): string
    {
        $blocks = [];

        foreach (array_unique($connectors) as $connector) {
            $usage = $this->forConnector($connector);

            if ($usage === null) {
                continue;
            }

            $keys = array_slice($usage['params'], 0, self::MAX_KEYS, true);
            $block = "### `{$connector}` — {$usage['uses']} usos reais\n"
                . 'Chaves de `params` observadas: ' . implode(', ', array_map(
                    fn (string $key, int $count) => "`{$key}` ({$count})",
                    array_keys($keys),
                    $keys,
                )) . '.';

            foreach ($usage['samples'] as $sample) {
                $block .= "\n" . json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $blocks[] = $block;
        }

        if ($blocks === []) {
            return '';
        }

        return "# COMO A LEO MADEIRAS ESCREVE ESSES CONECTORES\n\n"
            . "(extraído de {$this->pipelines()} pipelines reais em produção. "
            . 'A documentação da Digibee nomeia os parâmetros como aparecem na TELA ("Verb", "Stop On Client Error"); '
            . 'as chaves abaixo são as do JSON e são as que valem — use exatamente estas. '
            . 'Valores que endereçam uma máquina foram substituídos por `<endpoint>`/`<string>`; '
            . "expressões Double Braces são reais.)\n\n"
            . implode("\n\n", $blocks);
    }

    /**
     * The tenant's own `{{ global.* }}` names and account labels.
     *
     * Named rather than described, and capped: an invented `global.url-base-x`
     * validates clean and fails at runtime, so the cheapest fix is showing the
     * model the real list. Kept separate from the connector blocks because it
     * is relevant to any request, not only to one component.
     */
    public function referenceSection(): string
    {
        if ($this->globals() === [] && $this->accounts() === []) {
            return '';
        }

        $lines = ['# VARIÁVEIS GLOBAIS E CONTAS QUE EXISTEM NO TENANT', '',
            '(nomes reais. Nunca invente um: um `{{ global.x }}` inexistente passa na validação e quebra em execução. '
            . 'Se o que você precisa não estiver aqui, diga isso em vez de escolher um parecido.)', ''];

        if ($this->globals() !== []) {
            $lines[] = 'global: ' . implode(', ', array_map(fn (string $g) => "`{$g}`", $this->globals())) . '.';
        }

        if ($this->accounts() !== []) {
            $lines[] = 'accountLabel: ' . implode(', ', array_map(fn (string $a) => "`{$a}`", $this->accounts())) . '.';
        }

        return implode("\n", $lines);
    }

    /**
     * Connector names appearing in free text, so a request that names a
     * component by its JSON name pulls its own reference.
     *
     * @return list<string>
     */
    public function connectorsMentionedIn(string $text): array
    {
        $found = [];

        foreach (array_keys($this->data()['connectors'] ?? []) as $connector) {
            if (Str::contains($text, $connector)) {
                $found[] = $connector;
            }
        }

        return $found;
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $path = IndexPipelineVocabulary::path();

        // A checkout with no export indexed yet is a working state: the prompt
        // simply carries no vocabulary section, exactly as it did before this
        // existed.
        if (! is_file($path)) {
            return self::$data = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return self::$data = is_array($decoded) && ($decoded['version'] ?? null) === IndexPipelineVocabulary::VERSION
            ? $decoded
            : [];
    }

    public static function flush(): void
    {
        self::$data = null;
    }
}
