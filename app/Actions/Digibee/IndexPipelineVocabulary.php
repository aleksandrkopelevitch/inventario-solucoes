<?php

namespace App\Actions\Digibee;

use App\Services\Flowspec\CredentialScrubber;
use App\Support\Digibee\ParamRedactor;
use FilesystemIterator;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Reads the local pipeline export and writes
 * `database/data/digibee_tenant_vocabulary.json` — the half of the Digibee
 * knowledge base that Digibee's own documentation cannot supply.
 *
 * The docs describe a connector in UI labels ("Stop On Client Error"); a
 * flowSpec is written in JSON keys (`stopOnClientError`), and no published page
 * prints one. Our own pipelines are the only place both exist together, which
 * is why a connector card and this artifact are injected as a PAIR: the card
 * says what a parameter means, the vocabulary says what it is called and what
 * shape its value takes.
 *
 * **The export stays out of git; this artifact goes in.** `storage/app/private`
 * is gitignored in full, and the raw pipelines carry internal hostnames and IPs.
 * What ships is names and shapes — see ParamRedactor for exactly where that
 * line falls, and why `{{ global.* }}` and account labels are on the vocabulary
 * side of it.
 *
 * **A document with a credential leak is skipped whole.** CredentialScrubber
 * runs over each pipeline before anything is read out of it: a pipeline that
 * would fail the generator's own validation is not a pipeline to teach from,
 * and redacting around a leak is a weaker promise than refusing the file.
 */
class IndexPipelineVocabulary
{
    public const VERSION = 1;

    /** Where the artifact lives — config-driven so a test can redirect it. */
    public static function path(): string
    {
        return (string) config('services.digibee.vocabulary_path');
    }

    /** Params bigger than this after redaction are a payload template, not a shape lesson. */
    private const MAX_SAMPLE_CHARS = 1200;

    public function __construct(
        private readonly ParamRedactor $redactor,
        private readonly CredentialScrubber $scrubber,
    ) {}

    /**
     * @return array{pipelines: int, skipped: list<string>, connectors: int, globals: int, accounts: int, bytes: int}
     */
    public function handle(): array
    {
        $directory = Storage::disk('local')->path((string) config('services.digibee.pipelines_dir'));

        if (! is_dir($directory)) {
            throw new \RuntimeException("No pipeline export at {$directory} — run `php artisan digibee:pipelines:pull` first.");
        }

        $connectors = [];
        $globals = [];
        $accounts = [];
        $pipelines = 0;
        $skipped = [];

        foreach ($this->documents($directory) as $name => $document) {
            if ($this->scrubber->violations($document) !== []) {
                $skipped[] = $name;

                continue;
            }

            $pipelines++;

            foreach ($this->steps($document) as $step) {
                $this->collectExpressions($step, $globals, $accounts);

                if (($step['type'] ?? null) !== 'connector' || ! is_string($step['name'] ?? null)) {
                    continue;
                }

                $this->collectConnector($step, $connectors);
            }
        }

        ksort($connectors);
        sort($globals);
        sort($accounts);

        foreach ($connectors as &$connector) {
            ksort($connector['params']);
            arsort($connector['params']);
        }
        unset($connector);

        $json = json_encode([
            'version'      => self::VERSION,
            'generated_at' => now()->toIso8601String(),
            'pipelines'    => $pipelines,
            'connectors'   => $connectors,
            'globals'      => array_values($globals),
            'accounts'     => array_values($accounts),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        file_put_contents(self::path(), $json);

        return [
            'pipelines'  => $pipelines,
            'skipped'    => $skipped,
            'connectors' => count($connectors),
            'globals'    => count($globals),
            'accounts'   => count($accounts),
            'bytes'      => strlen($json),
        ];
    }

    /**
     * Every exported `{meta, flowSpec}` under the directory, keyed by
     * `Project/pipeline`.
     *
     * @return iterable<string, array<string, mixed>>
     */
    private function documents(string $directory): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $document = json_decode(ltrim((string) file_get_contents($file->getPathname()), "\xEF\xBB\xBF"), true);

            if (! is_array($document) || ! is_array($document['flowSpec'] ?? null)) {
                continue;
            }

            yield trim(str_replace($directory, '', $file->getPathname()), '/') => $document;
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @return iterable<array<string, mixed>>
     */
    private function steps(array $document): iterable
    {
        foreach ($document['flowSpec'] as $steps) {
            foreach (is_array($steps) ? $steps : [] as $step) {
                if (is_array($step)) {
                    yield $step;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, array{uses: int, params: array<string, int>, samples: list<array<string, mixed>>}>  $connectors
     */
    private function collectConnector(array $step, array &$connectors): void
    {
        $name = (string) $step['name'];
        $params = is_array($step['params'] ?? null) ? $step['params'] : [];

        $connectors[$name] ??= ['uses' => 0, 'params' => [], 'samples' => []];
        $connectors[$name]['uses']++;

        foreach (array_keys($params) as $key) {
            $connectors[$name]['params'][(string) $key] = ($connectors[$name]['params'][(string) $key] ?? 0) + 1;
        }

        $limit = (int) config('services.digibee.usage_samples');

        if (count($connectors[$name]['samples']) >= $limit || $params === []) {
            return;
        }

        $redacted = $this->redactor->value($params);
        $encoded = (string) json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (strlen($encoded) > self::MAX_SAMPLE_CHARS) {
            return;
        }

        // Two steps configured identically teach nothing twice; the samples are
        // meant to show the range of shapes a connector is used in.
        foreach ($connectors[$name]['samples'] as $existing) {
            if (json_encode($existing, JSON_UNESCAPED_SLASHES) === $encoded) {
                return;
            }
        }

        $connectors[$name]['samples'][] = $redacted;
    }

    /**
     * `{{ global.X }}` and account labels, wherever they appear in a step.
     *
     * Collected from the whole step rather than from a known key, because a
     * global is referenced from any Double Braces-supporting field and an
     * account arrives as `accountLabel` on some connectors and `accountLabels`
     * (a list) on others.
     *
     * @param  array<string, mixed>  $step
     * @param  list<string>  $globals
     * @param  list<string>  $accounts
     */
    private function collectExpressions(array $step, array &$globals, array &$accounts): void
    {
        foreach (['accountLabel', 'accountLabels'] as $key) {
            foreach ((array) ($step[$key] ?? $step['params'][$key] ?? []) as $label) {
                if (is_string($label) && $label !== '' && ! in_array($label, $accounts, true)) {
                    $accounts[] = $label;
                }
            }
        }

        $encoded = (string) json_encode($step, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (preg_match_all('/\{\{\s*global\.([A-Za-z0-9_.\-]+)/', $encoded, $matches) === false) {
            return;
        }

        foreach ($matches[1] as $global) {
            if (! in_array($global, $globals, true)) {
                $globals[] = $global;
            }
        }
    }
}
