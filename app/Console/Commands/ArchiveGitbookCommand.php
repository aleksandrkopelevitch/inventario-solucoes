<?php

namespace App\Console\Commands;

use App\Exceptions\GitbookApiException;
use App\Support\Gitbook\GitbookArchiveFormat;
use App\Support\Gitbook\GitbookArchiveWriter;
use App\Support\Gitbook\GitbookClient;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * `gitbook:archive` — one self-contained `.zip` per space, restorable with no
 * network and no GitBook.
 *
 * It is the half of the migration `gitbook:import` cannot be. The import writes
 * into the database, which is a live thing people edit; this writes a file, and
 * a file is what somebody still has in two years when the spaces are gone, the
 * token is revoked and the question is what a page used to say. The two share
 * their whole understanding of the corpus — the same client, the same asset
 * rules (`GitbookAssetReference`, `GitbookAssetResolver`) — so an archive holds
 * exactly what an import would have chased.
 *
 * ```
 * php artisan gitbook:archive --all
 * php artisan gitbook:archive --space=edu5p1Zks7fwfqwdtWhR --path=/mnt/backup
 * php artisan gitbook:restore storage/app/private/gitbook-archives/Docs--O3Qu….zip
 * ```
 *
 * **A space is skipped when its archive already exists**, unless `--force`.
 * This is dozens of spaces and hundreds of downloads over somebody else's API;
 * being able to re-run it after a failure without paying for the spaces that
 * already worked is what makes it usable at all. `--force` is how you refresh
 * one that has changed.
 *
 * Failures are per space and per asset, never fatal: a space that cannot be
 * read is reported and the run continues, because 37 archives plus a named
 * failure beats nothing plus a stack trace. The exit code still says whether
 * everything came across, so this can be trusted from a script.
 */
class ArchiveGitbookCommand extends Command
{
    protected $signature = 'gitbook:archive
        {--space=* : Space id to archive (repeatable)}
        {--all : Archive every space of the organization}
        {--org= : Organization id, when the token can read more than one}
        {--path= : Directory for the archives (default: storage/app/private/gitbook-archives)}
        {--force : Rewrite an archive that already exists instead of skipping it}';

    protected $description = 'Write each GitBook space to a self-contained .zip that gitbook:restore can read';

    public function handle(GitbookClient $client, GitbookArchiveWriter $writer): int
    {
        if (! $client->configured()) {
            $this->components->error(GitbookApiException::missingToken()->getMessage());

            return self::FAILURE;
        }

        $directory = rtrim($this->option('path') ?: storage_path('app/private/gitbook-archives'), '/');

        try {
            $spaces = $this->targets($client);
        } catch (GitbookApiException|ConnectionException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($spaces === []) {
            $this->components->error('Nothing to archive: pass --space=<id>, or --all, or start with `gitbook:import --list`.');

            return self::FAILURE;
        }

        $this->components->info(count($spaces) . ' space(s) → ' . $directory);

        $written = 0;
        $skipped = 0;
        $failed = [];
        $assetFailures = 0;

        foreach ($spaces as $i => $spaceId) {
            $label = $spaceId;

            try {
                // The title is needed for the file name, and it is also the
                // cheapest possible check that this space is readable at all —
                // better here than 200 page requests later.
                $title = trim((string) ($client->space($spaceId)['title'] ?? '')) ?: $spaceId;
                $label = $title;
                $destination = $directory . '/' . GitbookArchiveFormat::fileName($title, $spaceId);

                if (is_file($destination) && ! $this->option('force')) {
                    $this->components->twoColumnDetail(
                        $this->position($i, $spaces) . ' ' . $title,
                        '<fg=gray>já existe — use --force</>'
                    );
                    $skipped++;

                    continue;
                }

                $manifest = $writer->write($spaceId, $destination, $this->progress($i, $spaces, $title));

                $this->clearProgress();

                $this->components->twoColumnDetail(
                    $this->position($i, $spaces) . ' ' . $title,
                    $manifest['pages'] . ' páginas · ' . $manifest['assets'] . ' anexos'
                    . ' <fg=gray>(' . $manifest['assets_referenced'] . ' citados)</> · ' . $this->size((int) $manifest['asset_bytes'])
                );

                $written++;
                $assetFailures += count($manifest['asset_failures']);

                foreach ($manifest['asset_failures'] as $failure) {
                    $this->components->warn($title . ' · ' . $failure);
                }
            } catch (Throwable $e) {
                $this->clearProgress();
                $this->components->error($label . ': ' . $e->getMessage());
                $failed[] = $label;
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('Archives written', (string) $written);

        if ($skipped > 0) {
            $this->components->twoColumnDetail('Skipped (already there)', (string) $skipped);
        }

        if ($assetFailures > 0) {
            $this->components->twoColumnDetail('Assets not archived', '<fg=yellow>' . $assetFailures . '</>');
        }

        if ($failed !== []) {
            $this->components->twoColumnDetail('Spaces failed', '<fg=red>' . implode(', ', $failed) . '</>');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function targets(GitbookClient $client): array
    {
        /** @var array<int, string> $explicit */
        $explicit = $this->option('space');

        if (! $this->option('all')) {
            return $explicit;
        }

        $organizationId = $this->option('org') ?: $this->onlyOrganization($client);

        return array_values(array_filter(array_map(
            fn (array $space) => $space['id'] ?? null,
            $client->spaces($organizationId),
        )));
    }

    private function onlyOrganization(GitbookClient $client): string
    {
        $orgs = $client->organizations();

        if (count($orgs) === 1) {
            return (string) $orgs[0]['id'];
        }

        // Same refusal `gitbook:import` makes: more than one organization and
        // no --org would archive somebody else's, expensively and silently.
        throw new \RuntimeException(
            'This token can read ' . count($orgs) . ' organizations — pass --org=<id>. Run `gitbook:import --list` to see them.'
        );
    }

    /**
     * The in-place progress line, or nothing at all.
     *
     * `\r` only means something on a terminal: piped to a file or a CI log
     * every step would land on its own line, and a 150-page space would bury
     * the result it is reporting under 150 lines of noise. `isDecorated()` is
     * the same test Symfony uses to decide whether to emit colour.
     *
     * @param  array<int, string>  $all
     * @return Closure(string): void|null
     */
    private function progress(int $i, array $all, string $title): ?Closure
    {
        if (! $this->output->isDecorated()) {
            return null;
        }

        return fn (string $line) => $this->output->write(
            "\r  <fg=gray>" . $this->position($i, $all) . ' ' . $title . ' · ' . $line . str_repeat(' ', 20) . '</>'
        );
    }

    private function clearProgress(): void
    {
        if ($this->output->isDecorated()) {
            $this->output->write("\r" . str_repeat(' ', 110) . "\r");
        }
    }

    /** @param  array<int, string>  $all */
    private function position(int $i, array $all): string
    {
        return '[' . str_pad((string) ($i + 1), strlen((string) count($all)), ' ', STR_PAD_LEFT) . '/' . count($all) . ']';
    }

    private function size(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $power = min((int) floor(log($bytes, 1024)), count($units));

        return round($bytes / (1024 ** $power), 1) . ' ' . $units[$power - 1];
    }
}
