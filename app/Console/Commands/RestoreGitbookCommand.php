<?php

namespace App\Console\Commands;

use App\Actions\Documentation\ImportGitbookSpace;
use App\Contracts\GitbookSource;
use App\Support\Gitbook\GitbookArchive;
use App\Support\Gitbook\GitbookImportReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * `gitbook:restore` — point it at a `.zip` and get the caderno back, images and
 * attachments included, with nothing reaching the network.
 *
 * It is `gitbook:import` with its source swapped, and that is not a figure of
 * speech: it runs the same `ImportGitbookSpace`, rebinding `GitbookSource` to an
 * open `GitbookArchive` first. So everything the import learned the hard way
 * applies unchanged — the depth clamp, `group` nodes becoming empty section
 * pages, matching a page by title so a second run updates instead of
 * duplicating, and the asset rewrite that repoints the Markdown at
 * `/files/{id}` on OUR media. A restore is not a lesser import.
 *
 * ```
 * php artisan gitbook:restore storage/app/private/gitbook-archives/Docs--O3Qu….zip
 * php artisan gitbook:restore backup/*.zip --dated       # one caderno per space, dated
 * php artisan gitbook:restore backup/Arquitetura--IOjZ….zip --dry-run
 * ```
 *
 * Several archives can be named at once, because the shape of the backup is one
 * file per space and restoring "everything" is the normal case. Each is opened,
 * restored and closed in turn: an archive extracts to a temp directory, and
 * holding 38 of them open at once would be a copy of the whole corpus on disk
 * for no reason.
 */
class RestoreGitbookCommand extends Command
{
    protected $signature = 'gitbook:restore
        {archive* : Path to one or more .zip files written by gitbook:archive}
        {--notebook= : Name for the Notebook (defaults to the archived space title; single archive only)}
        {--dated : Restore into a dated snapshot caderno, "<Space>_imported_DD_MM_YYYY"}
        {--flat : Restore every page as a top-level one carrying its ancestry in the title}
        {--dry-run : Report what would be restored, without writing anything}';

    protected $description = 'Restore cadernos from .zip archives written by gitbook:archive — no network needed';

    public function handle(): int
    {
        /** @var array<int, string> $paths */
        $paths = $this->argument('archive');

        $notebookName = $this->option('notebook');

        if ($notebookName && count($paths) > 1) {
            $this->components->error('--notebook names a single caderno; pass exactly one archive with it.');

            return self::FAILURE;
        }

        // Both name the caderno, so together they say two things at once —
        // refused rather than resolved by precedence, exactly as in
        // `gitbook:import`.
        if ($notebookName && $this->option('dated')) {
            $this->components->error('--notebook and --dated both name the caderno; pass one.');

            return self::FAILURE;
        }

        $failed = [];

        foreach ($paths as $path) {
            if (! $this->restore($path, $notebookName)) {
                $failed[] = basename($path);
            }
        }

        if ($failed !== []) {
            $this->newLine();
            $this->components->twoColumnDetail('Archives failed', '<fg=red>' . implode(', ', $failed) . '</>');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function restore(string $path, ?string $notebookName): bool
    {
        $archive = null;

        try {
            $archive = GitbookArchive::open($path);

            $this->components->info(
                $archive->spaceTitle() . ' <fg=gray>(arquivado em ' . $this->when($archive->exportedAt()) . ')</>'
            );

            // The rebinding IS the feature: everything resolved from here down
            // — the import, its asset importer, that importer's resolver —
            // reads the archive instead of the API, without one of them knowing.
            $this->laravel->instance(GitbookSource::class, $archive);

            /** @var ImportGitbookSpace $import */
            $import = $this->laravel->make(ImportGitbookSpace::class);

            $report = $import->handle(
                spaceId: $archive->spaceId(),
                notebookName: $notebookName,
                nest: ! $this->option('flat'),
                dryRun: (bool) $this->option('dry-run'),
                dated: (bool) $this->option('dated'),
            );

            $this->report($report, $archive);

            return true;
        } catch (Throwable $e) {
            $this->components->error(basename($path) . ': ' . $e->getMessage());

            return false;
        } finally {
            // The extracted copy is this instance's, and it is the size of the
            // space — released before the next archive is opened rather than
            // when the process happens to end.
            $archive?->close();

            // Put the live API back, so nothing resolved after this run keeps
            // answering from an archive that no longer exists on disk.
            $this->laravel->forgetInstance(GitbookSource::class);
        }
    }

    private function report(GitbookImportReport $report, GitbookArchive $archive): void
    {
        if ($this->option('dry-run')) {
            $this->components->info(
                'Dry run · caderno "' . $report->notebookName . '" · ' . $report->pageCount() . ' página(s)'
            );

            foreach ($report->planned as $title) {
                $this->line('  <fg=gray>·</> ' . $title);
            }

            return;
        }

        $this->components->twoColumnDetail('Pages created', (string) $report->created);
        $this->components->twoColumnDetail('Pages updated', (string) $report->updated);

        if ($report->removed > 0) {
            $this->components->twoColumnDetail('Pages removed', $report->removed . ' <fg=gray>(no longer in the archive)</>');
        }

        $this->components->twoColumnDetail('Assets restored', (string) $report->assets);

        if ($report->sections > 0) {
            $this->components->twoColumnDetail('Sections (empty pages)', (string) $report->sections);
        }

        // What the ARCHIVE already knew it was missing, carried forward rather
        // than rediscovered: a restore that reported "0 failures" against an
        // archive written with 3 would be describing itself, not the content.
        $archived = (array) ($archive->manifest()['asset_failures'] ?? []);

        if ($archived !== []) {
            $this->components->warn(count($archived) . ' anexo(s) já faltavam no arquivo (não foram baixados na época):');

            foreach ($archived as $failure) {
                $this->line('  <fg=gray>·</> ' . $failure);
            }
        }

        foreach ($report->failures as $failure) {
            $this->components->warn($failure);
        }

        if ($report->notebook) {
            $this->line('  <fg=gray>' . route('notebooks.show', $report->notebook) . '</>');
        }
    }

    /** The archive's timestamp, readable, falling back to whatever it holds. */
    private function when(string $iso): string
    {
        return rescue(fn () => Carbon::parse($iso)->format('d/m/Y H:i'), $iso ?: '?', report: false);
    }
}
