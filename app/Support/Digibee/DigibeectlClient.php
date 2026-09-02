<?php

namespace App\Support\Digibee;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The thinnest possible wrapper over `digibeectl`, Digibee's official CLI.
 *
 * **This never runs on the server, and that is a security boundary rather than
 * a packaging detail.** The credential `digibeectl` holds is the interactive
 * one a developer logs in with, and its scope reaches creating and DELETING
 * deployments in production. Digibee publishes no read-only alternative: the
 * "Digibee APIs" product is in beta and its key covers the Pipeline Metrics API
 * only, so there is nothing narrower to authenticate with (checked against the
 * platform-administration docs, 2026-09-02). So the pull is a scheduled task on
 * a workstation or an ops box that publishes the derived artifact, and
 * `App\Actions\Digibee\PullDigibeePipelines` is never reachable from a queue,
 * a route or the app's own scheduler.
 *
 * The binary is a Windows executable under WSL for the person who has it, which
 * is why the path is configuration and why `available()` exists: a machine
 * without it must get a clear message, not a stack trace from `proc_open`.
 */
class DigibeectlClient
{
    public function binary(): string
    {
        return (string) config('services.digibee.ctl_bin');
    }

    public function available(): bool
    {
        return $this->binary() !== '' && is_file($this->binary());
    }

    /**
     * Every project in the realm.
     *
     * @return list<array<string, mixed>>
     */
    public function projects(): array
    {
        $projects = $this->json(['get', 'project', '-o']);

        return is_array($projects) ? array_values($projects) : [];
    }

    /**
     * Every pipeline of one project, following the CLI's own pagination
     * envelope (`{content: [...], last: bool}`).
     *
     * @return list<array<string, mixed>>
     */
    public function pipelines(string $projectId): array
    {
        $pipelines = [];
        $page = 1;

        while (true) {
            $result = $this->json(['get', 'pipeline', '--project-id', $projectId, '--page', (string) $page, '-o']);
            $pipelines = [...$pipelines, ...($result['content'] ?? [])];

            if (($result['last'] ?? true) === true) {
                break;
            }

            $page++;
        }

        return $pipelines;
    }

    /** One pipeline's `{meta, flowSpec}` document. */
    public function flowspec(string $pipelineId): array
    {
        $document = $this->json(['get', 'pipeline', '--flowspec', '--pipeline-id', $pipelineId, '-o']);

        return is_array($document) ? $document : [];
    }

    /**
     * @param  list<string>  $arguments
     */
    private function json(array $arguments): mixed
    {
        if (! $this->available()) {
            throw new RuntimeException("digibeectl not found at {$this->binary()} — set DIGIBEECTL_BIN.");
        }

        $result = Process::timeout((int) config('services.digibee.ctl_timeout'))
            ->run([$this->binary(), ...$arguments]);

        if ($result->failed()) {
            throw new RuntimeException(
                'digibeectl ' . implode(' ', $arguments) . ' exited with ' . $result->exitCode()
                . ': ' . trim($result->errorOutput())
            );
        }

        // The Windows build emits a UTF-8 BOM, which json_decode refuses.
        return json_decode(ltrim($result->output(), "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
    }
}
