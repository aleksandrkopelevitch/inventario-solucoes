<?php

namespace App\Console\Commands;

use App\Models\DocumentationPage;
use App\Support\Documentation\SecretText;
use Illuminate\Console\Command;

/**
 * `documentation:secrets-audit` — lists the pages whose protected values show
 * the marks of the ORDINAL bug (fixed 2026-10-16, see
 * .claude/rules/docs-secrets.md). Read-only, and it never prints a value.
 *
 * From the feature's first deploy (shipped 2026-08-31) until that fix, a value
 * was addressed by its position. An editing session that added, deleted or
 * moved one renumbered the page on its first save, and every later save put
 * values back in the wrong places. Two of the three outcomes leave a trace in
 * the stored text, and those are what this reports:
 *
 * - BROKEN: a marker stored as text — `[[SECRET-3]]` as the body of a value
 *   (a value deleted above it, the editor still naming the old number), or one
 *   standing in the prose. Stored text never holds a marker legitimately, so
 *   the value that was there is gone.
 * - SUSPECT: two values of one page identical. A value added above the others
 *   was copied over them, one save at a time. A page can also repeat a value on
 *   purpose (the same token in two samples), which is why this is a suspicion
 *   and the ordinals are given, for an admin to open the page and reveal them.
 *
 * The third outcome leaves none: two values SWAPPED (each one is still a real
 * value, in the other's place), and neither does text typed into a value by
 * accident. Only a backup taken before the page's damaging save can tell those
 * apart, which is why `--all` lists every page holding protected values with
 * the date it was last saved: a page last saved before the feature's first
 * deploy cannot have been touched.
 *
 * Values are referred to by ordinal — "the 2nd protected value on the page", in
 * reading order — never by content. The output ends up in a terminal, in a
 * scrollback and maybe in a ticket, and the plaintext has exactly one door
 * (App\Actions\Documentation\RevealPageSecret).
 */
class AuditDocumentationSecretsCommand extends Command
{
    protected $signature = 'documentation:secrets-audit
        {--notebook= : Only this caderno (its slug)}
        {--all : Also list the pages with protected values that show no sign of damage}';

    protected $description = 'List documentation pages whose protected values were damaged by the ordinal bug (read-only, prints no value)';

    /** A marker, anywhere — stored text should never contain one. */
    private const MARKER = '/\[\[SECRET-[a-z0-9]+\]\]/';

    public function handle(): int
    {
        $checked = 0;
        $broken = 0;
        $suspect = 0;

        DocumentationPage::query()
            ->where('documentation', 'like', '%secret%')
            ->when($this->option('notebook'), fn ($query, string $slug) => $query->whereRelation('notebook', 'slug', $slug))
            ->select(['id', 'notebook_id', 'title', 'slug', 'documentation', 'updated_at'])
            ->with('notebook:id,name,slug')
            ->lazyById()
            ->each(function (DocumentationPage $page) use (&$checked, &$broken, &$suspect) {
                $values = SecretText::values($page->documentation);

                if ($values === []) {
                    return;
                }

                $checked++;
                [$problems, $isBroken] = $this->diagnose((string) $page->documentation, $values);

                if ($problems === [] && ! $this->option('all')) {
                    return;
                }

                if ($isBroken) {
                    $broken++;
                } elseif ($problems !== []) {
                    $suspect++;
                }

                $this->report($page, count($values), $problems);
            });

        $this->newLine();
        $this->components->info("Checked {$checked} page(s) with protected values: {$broken} broken, {$suspect} suspect.");

        if ($broken + $suspect > 0) {
            $this->components->bulletList([
                'BROKEN: the value at that position was overwritten; only a backup has it.',
                'SUSPECT: open the page as an admin and reveal the values named, to tell a repeat on purpose from a copy.',
            ]);
        }

        $this->components->warn(
            'Swapped values, and text typed into a value, leave no trace a scan can see. '
            . 'Compare against a backup from before each page\'s last save (--all lists them with that date).'
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $values  keyed by ordinal, from 1
     * @return array{0: list<string>, 1: bool} the problems, and whether any is certain
     */
    private function diagnose(string $markdown, array $values): array
    {
        $problems = [];

        $dead = array_keys(array_filter($values, fn (string $value) => preg_match(self::MARKER, $value) === 1));

        if ($dead !== []) {
            $problems[] = '<fg=red>BROKEN</> ' . $this->ordinals($dead, 'holds', 'hold') . ' the text of a marker instead of a value';
        }

        // Markers in the prose, outside any value: every marker in the text
        // minus the ones inside the values already counted above.
        $inValues = array_sum(array_map(fn (string $value) => preg_match_all(self::MARKER, $value), $values));
        $loose = preg_match_all(self::MARKER, $markdown) - $inValues;

        if ($loose > 0) {
            $problems[] = "<fg=red>BROKEN</> {$loose} marker(s) in the prose, outside any value";
        }

        // Identical values, the dead ones left out — they are reported above
        // and the same marker text twice says nothing more.
        $groups = [];
        foreach (array_diff_key($values, array_flip($dead)) as $ordinal => $value) {
            $groups[$value][] = $ordinal;
        }

        foreach ($groups as $ordinals) {
            if (count($ordinals) > 1) {
                $problems[] = '<fg=yellow>SUSPECT</> ' . $this->ordinals($ordinals, 'is', 'are') . ' identical';
            }
        }

        return [$problems, $dead !== [] || $loose > 0];
    }

    /** @param  list<string>  $problems */
    private function report(DocumentationPage $page, int $count, array $problems): void
    {
        $saved = $page->updated_at?->timezone(config('app.display_timezone'))->format('d/m/Y H:i') ?? '—';

        $this->newLine();
        $this->components->twoColumnDetail(
            "<options=bold>{$page->notebook->name} › {$page->title}</>",
            "{$count} value(s) · last saved {$saved}",
        );

        foreach ($problems === [] ? ['<fg=gray>no sign of damage</>'] : $problems as $problem) {
            $this->line("    {$problem}");
        }

        $this->line('    <fg=gray>' . route('notebooks.pages.edit', [$page->notebook, $page]) . '</>');
    }

    /** "value #3", "values #1 and #2", "values #1, #2 and #4". */
    private function ordinals(array $ordinals, string $singular, string $plural): string
    {
        $labels = array_map(fn (int $ordinal) => "#{$ordinal}", $ordinals);
        $last = array_pop($labels);

        return $labels === []
            ? "value {$last} {$singular}"
            : 'values ' . implode(', ', $labels) . " and {$last} {$plural}";
    }
}
