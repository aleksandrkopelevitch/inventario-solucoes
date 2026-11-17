<?php

namespace App\Console\Commands;

use App\Mcp\Support\ShrinkGuard;
use App\Models\DocumentationChat;
use App\Models\DocumentationChatMessage;
use App\Models\DocumentationPage;
use App\Models\McpWrite;
use Illuminate\Console\Command;

/**
 * Replays `update_page`'s shrink guard (App\Mcp\Support\ShrinkGuard) over
 * rewrites that really happened, to say whether its thresholds refuse
 * legitimate ones — the calibration docs-and-specs/mcp-write-tools.md §12 asks
 * for before the numbers are trusted.
 *
 * The evidence is the Assiste IA's history: every assistant reply with a
 * `draft` is a whole-page rewrite by a MODEL, the exact shape `update_page`
 * receives, and `applied_at` says whether a PERSON accepted it. An applied
 * draft the guard would have refused is a false positive: a rewrite somebody
 * wanted, which the MCP would have bounced back for confirmation. Drafts never
 * applied are shown beside them, as the closest thing to "rewrites nobody
 * wanted". The MCP's own `update_page` writes are reported too, though they
 * already passed the guard (by not shrinking, or by being confirmed).
 *
 * Read-only: it writes nothing and prints no page text. Run it as www-data in
 * production, like every artisan command there.
 */
class CalibrateShrinkGuardCommand extends Command
{
    protected $signature = 'mcp:calibrate-shrink-guard
        {--ratios=0.4,0.5,0.6,0.7 : Shares of the old body below which a rewrite counts as shrunk}
        {--min=1000,2000,4000 : Page lengths (characters) below which the guard does not apply}';

    protected $description = "Replay update_page's shrink guard over real Assiste IA rewrites and MCP writes (read-only)";

    public function handle(): int
    {
        $ratios = $this->numbers('ratios', fn (string $v) => (float) $v);
        $minimums = $this->numbers('min', fn (string $v) => (int) $v);

        $this->corpus($minimums);
        $rewrites = $this->assistantRewrites();
        $this->grid($rewrites, $ratios, $minimums);
        $this->percentiles($rewrites);
        $this->mcpWrites();

        $this->newLine();
        $this->line('Guarda atual: recusa abaixo de ' . (int) (ShrinkGuard::RATIO * 100) . '% em páginas com mais de '
            . ShrinkGuard::MIN_CHARS . ' caracteres (App\\Mcp\\Support\\ShrinkGuard).');
        $this->line('Leitura: se a guarda recusa mais que uns 5% dos rascunhos APLICADOS, ela está apertada demais;');
        $this->line('se não recusa nenhum dos rascunhos descartados que encolheram muito, está frouxa demais.');

        return self::SUCCESS;
    }

    /** @param list<int> $minimums */
    private function corpus(array $minimums): void
    {
        $lengths = [];

        DocumentationPage::query()->select(['id', 'documentation'])->lazyById(200)
            ->each(function (DocumentationPage $page) use (&$lengths) {
                $lengths[] = mb_strlen((string) $page->documentation);
            });

        sort($lengths);

        $this->info('Corpus');
        $this->table(['páginas', 'mediana (caracteres)', ...array_map(fn (int $m) => "> {$m} caracteres", $minimums)], [[
            count($lengths),
            self::percentile($lengths, 50),
            ...array_map(fn (int $m) => count(array_filter($lengths, fn (int $l) => $l > $m)), $minimums),
        ]]);
    }

    /**
     * Each assistant draft paired with the page text it was written from —
     * the `existing_content` of the user turn it answers. One query per chat,
     * never per message.
     *
     * @return list<array{old: int, new: int, applied: bool}>
     */
    private function assistantRewrites(): array
    {
        $rewrites = [];

        DocumentationChat::query()->select('id')->lazyById(100)->each(function (DocumentationChat $chat) use (&$rewrites) {
            $existing = null;

            $chat->messages()
                ->reorder('id')
                ->get(['id', 'role', 'existing_content', 'draft', 'applied_at'])
                ->each(function (DocumentationChatMessage $message) use (&$existing, &$rewrites) {
                    if ($message->role === 'user') {
                        $existing = $message->existing_content;

                        return;
                    }

                    if ($message->draft !== null && $existing !== null) {
                        $rewrites[] = [
                            'old'     => mb_strlen((string) $existing),
                            'new'     => mb_strlen((string) $message->draft),
                            'applied' => $message->applied_at !== null,
                        ];
                    }
                });
        });

        return $rewrites;
    }

    /**
     * @param  list<array{old: int, new: int, applied: bool}>  $rewrites
     * @param  list<float>  $ratios
     * @param  list<int>  $minimums
     */
    private function grid(array $rewrites, array $ratios, array $minimums): void
    {
        $applied = array_values(array_filter($rewrites, fn (array $r) => $r['applied']));
        $discarded = array_values(array_filter($rewrites, fn (array $r) => ! $r['applied']));

        $this->newLine();
        $this->info('Rascunhos do Assiste IA: ' . count($applied) . ' aplicados, ' . count($discarded) . ' descartados');

        $refused = fn (array $set, float $ratio, int $min) => count(array_filter(
            $set,
            fn (array $r) => ShrinkGuard::band($r['old'], $r['new'], $ratio, $min) !== null,
        ));

        $rows = [];

        foreach ($minimums as $min) {
            foreach ($ratios as $ratio) {
                $a = $refused($applied, $ratio, $min);
                $d = $refused($discarded, $ratio, $min);
                $current = abs($ratio - ShrinkGuard::RATIO) < 1e-9 && $min === ShrinkGuard::MIN_CHARS;

                $rows[] = [
                    ($current ? '→ ' : '') . '< ' . (int) ($ratio * 100) . '%',
                    "> {$min}",
                    $a . ' (' . self::share($a, count($applied)) . ')',
                    $d . ' (' . self::share($d, count($discarded)) . ')',
                ];
            }
        }

        $this->table(['encolheu para', 'página com', 'aplicados recusados', 'descartados recusados'], $rows);
    }

    /** @param list<array{old: int, new: int, applied: bool}> $rewrites */
    private function percentiles(array $rewrites): void
    {
        $shares = array_map(
            fn (array $r) => (int) round($r['new'] / $r['old'] * 100),
            array_filter($rewrites, fn (array $r) => $r['applied'] && $r['old'] > ShrinkGuard::MIN_CHARS),
        );

        sort($shares);

        $this->newLine();
        $this->info('Quanto do texto um rascunho APLICADO manteve (páginas > ' . ShrinkGuard::MIN_CHARS . ' caracteres, ' . count($shares) . ' casos)');
        $this->table(['p5', 'p10', 'p25', 'mediana'], [[
            ...array_map(fn (int $p) => $shares === [] ? '—' : self::percentile($shares, $p) . '%', [5, 10, 25, 50]),
        ]]);
    }

    private function mcpWrites(): void
    {
        $shrunk = 0;
        $total = 0;

        McpWrite::query()->where('tool', 'update_page')->lazyById(100)->each(function (McpWrite $write) use (&$shrunk, &$total) {
            if (! isset($write->before['documentation'], $write->after['documentation'])) {
                return;
            }

            $total++;
            $old = mb_strlen((string) $write->before['documentation']);
            $new = mb_strlen((string) $write->after['documentation']);

            if (ShrinkGuard::band($old, $new) !== null) {
                $shrunk++;
            }
        });

        $this->newLine();
        $this->info("Escritas do MCP (update_page com texto): {$total}; {$shrunk} encolheram além da guarda e passaram com confirmação.");
    }

    /**
     * @template T
     *
     * @param  callable(string): T  $cast
     * @return list<T>
     */
    private function numbers(string $option, callable $cast): array
    {
        return array_values(array_map($cast, array_filter(array_map('trim', explode(',', (string) $this->option($option))), 'is_numeric')));
    }

    /** @param list<int> $sorted */
    private static function percentile(array $sorted, int $p): int
    {
        if ($sorted === []) {
            return 0;
        }

        return $sorted[(int) floor(($p / 100) * (count($sorted) - 1))];
    }

    private static function share(int $part, int $whole): string
    {
        return $whole === 0 ? '—' : round($part / $whole * 100, 1) . '%';
    }
}
