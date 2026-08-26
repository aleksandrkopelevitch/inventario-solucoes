<?php

namespace App\Console\Commands;

use App\Actions\Documentation\ImportGitbookSpace;
use App\Exceptions\GitbookApiException;
use App\Models\DocumentationPage;
use App\Support\Gitbook\GitbookClient;
use App\Support\Gitbook\GitbookImportReport;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

/**
 * Pulls GitBook content into the documentation hub.
 *
 * `--list` first: a space is addressed by its opaque id, and this is the only
 * place to see which id is which title. Then `--space=<id>` for one space, or
 * `--all` for every space in an organization.
 *
 * The import is read-only against GitBook and re-runnable (see
 * ImportGitbookSpace), so `--dry-run` and a real run differ only in whether
 * anything is written — the same requests are made either way.
 */
class ImportGitbookCommand extends Command
{
    protected $signature = 'gitbook:import
        {--list : List the organizations and spaces this token can read, with their ids}
        {--space=* : Space id to import (repeatable)}
        {--all : Import every space of the organization}
        {--org= : Organization id, when the token can read more than one}
        {--group= : Name for the DocumentationGroup (defaults to the space title; single space only)}
        {--flat : Import every page as a top-level one carrying its GitBook ancestry in the title, instead of reproducing the nesting}
        {--dry-run : Fetch and report what would be imported, without writing anything}';

    protected $description = 'Import GitBook spaces into standalone documentation groups';

    public function handle(GitbookClient $client, ImportGitbookSpace $import): int
    {
        if (! $client->configured()) {
            $this->components->error(GitbookApiException::missingToken()->getMessage());

            return self::FAILURE;
        }

        try {
            if ($this->option('list')) {
                return $this->list($client);
            }

            $spaces = $this->targets($client);

            if ($spaces === []) {
                $this->components->error('Nothing to import: pass --space=<id>, or --all, or start with --list.');

                return self::FAILURE;
            }

            if ($this->option('group') && count($spaces) > 1) {
                $this->components->error('--group names a single group; use it with exactly one --space.');

                return self::FAILURE;
            }

            foreach ($spaces as $spaceId) {
                $this->report($import->handle(
                    spaceId: $spaceId,
                    groupName: $this->option('group'),
                    nest: ! $this->option('flat'),
                    dryRun: (bool) $this->option('dry-run'),
                ));
            }
        } catch (GitbookApiException $e) {
            // Caught here, not left to bubble: this is the one consumer, and a
            // stack trace tells the operator nothing the message doesn't.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (ConnectionException $e) {
            // The network gave up even after the macro's retries. Worth saying
            // out loud that nothing is lost: the import is re-runnable, and
            // pages already brought over will be updated rather than doubled.
            $this->components->error(
                'Could not reach GitBook: ' . $e->getMessage()
                . ' Already-imported pages were kept — re-run the same command to continue.'
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function list(GitbookClient $client): int
    {
        foreach ($client->organizations() as $org) {
            $this->newLine();
            $this->components->info($org['title'] . '  (--org=' . $org['id'] . ')');

            $spaces = $client->spaces($org['id']);

            if ($spaces === []) {
                $this->line('  <fg=gray>no spaces</>');

                continue;
            }

            $this->table(
                ['Space', 'id (--space=)', 'Visibility'],
                array_map(fn (array $space) => [
                    $space['title'] ?? '—',
                    $space['id'] ?? '—',
                    $space['visibility'] ?? '—',
                ], $spaces),
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
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

        // More than one and no --org would silently import somebody else's
        // documentation, so it asks instead of picking.
        return (string) $this->choice(
            'Which organization?',
            array_combine(
                array_map(fn (array $org) => (string) $org['id'], $orgs),
                array_map(fn (array $org) => (string) $org['title'], $orgs),
            ),
        );
    }

    private function report(GitbookImportReport $report): void
    {
        $this->newLine();

        if (! $report->group) {
            $this->components->info(
                'Dry run · ' . $report->spaceTitle . ' · ' . $report->pageCount() . ' page(s) would be imported'
            );

            // No numbering here: the lines are already indented by depth, and a
            // running count down the left edge fights the shape it is showing.
            foreach ($report->planned as $title) {
                $this->line('  <fg=gray>·</> ' . $title);
            }
        } else {
            $this->components->info($report->spaceTitle . ' → group "' . $report->group->name . '"');
            $this->components->twoColumnDetail('Pages created', (string) $report->created);
            $this->components->twoColumnDetail('Pages updated', (string) $report->updated);
            $this->components->twoColumnDetail('Assets re-hosted', (string) $report->assets);

            if ($report->sections > 0) {
                $this->components->twoColumnDetail('Sections (empty pages)', (string) $report->sections);
            }
            $this->line('  <fg=gray>' . route('documentation.groups.show', $report->group) . '</>');
        }

        // Not a failure, but the one thing about a nested import an operator
        // cannot see from the result: how much of the space was too deep to
        // keep as levels and reads as a prefixed title instead.
        if ($report->collapsed > 0) {
            $this->components->warn(
                $report->collapsed . ' page(s) sat deeper than ' . DocumentationPage::MAX_DEPTH
                . ' levels — the ancestry that did not fit is in their titles instead'
            );
        }

        foreach ($report->skipped as $type => $count) {
            if ($count > 0) {
                $this->components->warn($count . ' ' . $type . ' page(s) skipped — ' . match ($type) {
                    'link'     => 'sidebar links to elsewhere, no content of their own',
                    'computed' => 'generated live from an OpenAPI spec; import the spec at its source instead',
                    default    => 'unsupported node type',
                });
            }
        }

        foreach ($report->failures as $failure) {
            $this->components->warn('Asset left on GitBook — ' . $failure);
        }
    }
}
