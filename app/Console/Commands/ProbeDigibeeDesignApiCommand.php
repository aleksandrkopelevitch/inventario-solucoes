<?php

namespace App\Console\Commands;

use App\Actions\Digibee\ProbeDigibeeDesignApi;
use App\Support\Digibee\DigibeeAuthResolver;
use Illuminate\Console\Command;

/**
 * Read-only verification that Digibee's platform API answers the routes the
 * autonomous lifecycle (APLA) needs.
 *
 * This is the first task of that feature and it is a command rather than
 * anything reachable from the app, because what it produces is a FINDING, not
 * a behaviour: whether a flowSpec can be written into a pipeline through the
 * design routes at all. `digibeectl` covers deploy, redeploy, status, metrics
 * and history as supported verbs; `create pipeline` takes only
 * --name/--description/--project and makes an empty shell, so the upsert is the
 * single operation with no published interface, and everything after it in the
 * roadmap depends on the answer.
 *
 * Deliberately absent from `routes/console.php`. Nothing about it is periodic —
 * it is run by a person, once, when the credential or the route changes.
 */
class ProbeDigibeeDesignApiCommand extends Command
{
    protected $signature = 'digibee:design:probe
        {--pipeline-id= : Read this pipeline back instead of borrowing an id from the list}
        {--environment=test : Which environment to list deployments for}
        {--diagnose : Resolve the credential and stop — makes no network call at all}';

    protected $description = 'Verify (read-only) that the Digibee platform API answers the routes the lifecycle agent needs';

    public function handle(DigibeeAuthResolver $auth, ProbeDigibeeDesignApi $probe): int
    {
        $this->credentialTable($auth);

        if ($this->option('diagnose')) {
            return $auth->resolve()->complete() ? self::SUCCESS : self::FAILURE;
        }

        $report = $probe->handle(
            pipelineId: $this->option('pipeline-id') ?: null,
            environment: (string) $this->option('environment'),
        );

        if (! $report->credentials->complete()) {
            $this->components->error(
                'Incomplete credential: missing ' . implode(', ', $report->credentials->missing())
                . '. Nothing was called.'
            );

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['route', 'method', 'path', 'status', 'note'],
            array_map(fn (array $step) => [
                $step['label'],
                $step['method'],
                $step['path'],
                $step['ok'] ? "<info>{$step['status']}</info>" : '<comment>' . ($step['status'] ?? '—') . '</comment>',
                $step['note'],
            ], $report->steps),
        );

        // The shapes are the durable half of the output: they are what the
        // ingestion payload gets modelled on, and they are the reason this is
        // worth running before anything is written against these routes.
        foreach ($report->steps as $step) {
            if ($step['shape'] !== []) {
                $this->line("  <fg=gray>{$step['label']}:</> " . implode(', ', $step['shape']));
            }
        }

        $this->newLine();

        if ($report->reachedDetail) {
            $missing = $report->missingRoundTripKeys();

            $this->components->twoColumnDetail(
                'round-trip keys present',
                count($report->roundTripKeys) . '/' . (count($report->roundTripKeys) + count($missing)),
            );

            if ($missing !== []) {
                $this->components->warn('absent from the detail response: ' . implode(', ', $missing));
            }
        }

        $report->ok()
            ? $this->components->info($report->verdict())
            : $this->components->warn($report->verdict());

        return $report->ok() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * What resolved and from where — names, sources and lengths, never a byte
     * of any value. See DigibeeCredentials::diagnose(): a length is the one
     * property worth showing, because a token truncated by a shell quoting
     * mistake answers 401 with the same message an expired one does.
     */
    private function credentialTable(DigibeeAuthResolver $auth): void
    {
        $credentials = $auth->resolve();

        $this->components->twoColumnDetail('digibeectl config', $auth->configPath()
            . (is_file($auth->configPath()) ? '' : ' <comment>(absent)</comment>'));

        $this->table(
            ['field', 'resolved', 'source', 'length'],
            array_map(fn (array $row) => [
                $row['field'],
                $row['resolved'] ? '<info>yes</info>' : '<comment>no</comment>',
                $row['source'],
                $row['length'] === 0 ? '—' : (string) $row['length'],
            ], $credentials->diagnose()),
        );
    }
}
