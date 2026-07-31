#!/usr/bin/env php
<?php

/**
 * Dev-only tool — NOT wired into Laravel's console kernel and NOT meant to
 * run in CI/production: it depends on `digibeectl` (Digibee's official CLI),
 * which only exists on a developer's own machine, authenticated against the
 * real Leo Madeiras Digibee tenant.
 *
 * Diffs the F8 flowSpec generator's hand-curated catalog
 * (database/data/digibee_component_catalog.json) against the connector names
 * and step types actually used across real, currently-deployed Digibee
 * pipelines — either a local export folder or a fresh pull via `digibeectl`.
 *
 * This tool only ever PRINTS a report. It never writes to
 * digibee_component_catalog.json or the flowSpec example corpus — adding a
 * connector unblocks DigibeeFlowspecValidator, but the model still needs a
 * human-curated example teaching that connector's real param shape (see
 * FlowspecExampleSeeder / database/data/digibee_flowspec_examples/), which
 * this script does not attempt to generate.
 *
 * Usage:
 *   php flowspec-catalog-audit.php                 # scan the existing local export
 *   php flowspec-catalog-audit.php --refresh        # re-pull every pipeline via digibeectl first
 *   php flowspec-catalog-audit.php --dir=/path      # use a different export folder
 *   php flowspec-catalog-audit.php --bin=/path/to/digibeectl.exe
 *
 * Env vars (used when the matching flag is omitted):
 *   DIGIBEE_FLOWSPECS_DIR  default: /mnt/c/Users/alexandre.kopelevitc/digibee-flowspecs
 *   DIGIBEECTL_BIN         default: /mnt/c/Users/alexandre.kopelevitc/digibeectl/digibeectl.exe
 */
$options = getopt('', ['refresh', 'dir::', 'bin::']);

$exportDir = $options['dir'] ?? getenv('DIGIBEE_FLOWSPECS_DIR') ?: '/mnt/c/Users/alexandre.kopelevitc/digibee-flowspecs';
$digibeectl = $options['bin'] ?? getenv('DIGIBEECTL_BIN') ?: '/mnt/c/Users/alexandre.kopelevitc/digibeectl/digibeectl.exe';
$refresh = array_key_exists('refresh', $options);

if ($refresh) {
    refreshExport($digibeectl, $exportDir);
}

['stepTypes' => $usedStepTypes, 'connectors' => $usedConnectors] = scanExport($exportDir);

$catalogPath = __DIR__ . '/database/data/digibee_component_catalog.json';
$catalog = json_decode(file_get_contents($catalogPath), true, 512, JSON_THROW_ON_ERROR);

report('Step types', $usedStepTypes, $catalog['step_types']);
report('Connector names', $usedConnectors, $catalog['connector_names']);

echo "\nThis only diffs NAMES against real production usage. Adding a name to\n"
    . "the catalog just unblocks DigibeeFlowspecValidator — it does NOT teach the\n"
    . "model that connector's param shape. Any \"missing\" entry above still needs\n"
    . "a human-curated example in database/data/digibee_flowspec_examples/ before\n"
    . "it's genuinely usable by the F8 generator (see rabbitmq-publish-retry.json\n"
    . "for the reference pattern).\n";

/** Re-pulls every project's pipelines and their flowSpecs into $dir via digibeectl. */
function refreshExport(string $digibeectl, string $dir): void
{
    fwrite(STDERR, "Refreshing {$dir} via digibeectl...\n");

    $projects = digibeectlJson($digibeectl, ['get', 'project', '-o']);

    foreach ($projects as $project) {
        $projectName = $project['name'];
        $projectDir = $dir . '/' . $projectName;

        if (str_starts_with($projectName, '.')) {
            continue; // e.g. ".ainative-drafts" — not a real integration project
        }

        $pipelines = fetchAllPipelines($digibeectl, $project['id']);

        if ($pipelines === []) {
            continue;
        }

        if (! is_dir($projectDir)) {
            mkdir($projectDir, 0777, true);
        }

        foreach ($pipelines as $pipeline) {
            $pipelineId = $pipeline['latest']['_id'] ?? null;
            $pipelineName = $pipeline['_id']['name'] ?? null;

            if (! is_string($pipelineId) || ! is_string($pipelineName)) {
                continue;
            }

            fwrite(STDERR, "  {$projectName}/{$pipelineName}\n");

            $flowspec = digibeectlJson($digibeectl, [
                'get', 'pipeline', '--flowspec', '--pipeline-id', $pipelineId, '-o',
            ]);

            file_put_contents(
                $projectDir . '/' . $pipelineName . '.json',
                json_encode($flowspec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        }
    }

    fwrite(STDERR, "Refresh done.\n\n");
}

/** @return list<array<string, mixed>> */
function fetchAllPipelines(string $digibeectl, string $projectId): array
{
    $pipelines = [];
    $page = 1;

    while (true) {
        $result = digibeectlJson($digibeectl, [
            'get', 'pipeline', '--project-id', $projectId, '--page', (string) $page, '-o',
        ]);

        $pipelines = array_merge($pipelines, $result['content'] ?? []);

        if (($result['last'] ?? true) === true) {
            break;
        }

        $page++;
    }

    return $pipelines;
}

/** @param list<string> $args */
function digibeectlJson(string $digibeectl, array $args): mixed
{
    $command = array_merge([$digibeectl], $args);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('Failed to launch digibeectl: ' . implode(' ', $command));
    }

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException('digibeectl exited with code ' . $exitCode . ': ' . implode(' ', $args));
    }

    return json_decode(ltrim($output, "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
}

/** @return array{stepTypes: array<string, int>, connectors: array<string, int>} */
function scanExport(string $dir): array
{
    $stepTypes = [];
    $connectors = [];

    // PHP's glob() has no true recursive `**`, so walk the tree manually.
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $fileInfo) {
        if ($fileInfo->getExtension() === 'json') {
            walkFile($fileInfo->getPathname(), $stepTypes, $connectors);
        }
    }

    return ['stepTypes' => $stepTypes, 'connectors' => $connectors];
}

/**
 * @param  array<string, int>  $stepTypes
 * @param  array<string, int>  $connectors
 */
function walkFile(string $file, array &$stepTypes, array &$connectors): void
{
    $document = json_decode(ltrim(file_get_contents($file), "\xEF\xBB\xBF"), true);

    if (! is_array($document) || ! is_array($document['flowSpec'] ?? null)) {
        return;
    }

    foreach ($document['flowSpec'] as $steps) {
        if (! is_array($steps)) {
            continue;
        }

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $type = $step['type'] ?? null;

            if (is_string($type) && $type !== '') {
                $stepTypes[$type] = ($stepTypes[$type] ?? 0) + 1;
            }

            if ($type === 'connector' && is_string($step['name'] ?? null) && $step['name'] !== '') {
                $connectors[$step['name']] = ($connectors[$step['name']] ?? 0) + 1;
            }
        }
    }
}

/**
 * @param  array<string, int>  $used
 * @param  list<string>  $cataloged
 */
function report(string $label, array $used, array $cataloged): void
{
    arsort($used);
    $missing = array_diff(array_keys($used), $cataloged);
    $unused = array_diff($cataloged, array_keys($used));

    echo "=== {$label} ===\n";

    echo "In production but NOT in catalog:\n";

    if ($missing === []) {
        echo "  (none)\n";
    }

    foreach ($missing as $name) {
        echo "  {$name}: {$used[$name]} occurrences\n";
    }

    echo "In catalog but not seen in this export:\n";

    if ($unused === []) {
        echo "  (none)\n";
    }

    foreach ($unused as $name) {
        echo "  {$name}\n";
    }

    echo "\n";
}
