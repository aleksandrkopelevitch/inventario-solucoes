<?php

namespace App\Actions\Digibee;

use App\Support\Digibee\DigibeectlClient;
use Closure;
use Illuminate\Support\Facades\Storage;

/**
 * Exports every pipeline of every project into the local corpus, via
 * `digibeectl`.
 *
 * This is the read side of what `flowspec-catalog-audit.php --refresh` has done
 * by hand since the catalog existed — the same three calls in the same order —
 * moved into the app so the export has one home, one configured path, and a
 * derived artifact (IndexPipelineVocabulary) that the flowSpec prompt can
 * actually read.
 *
 * **It is deliberately not schedulable inside the app.** See DigibeectlClient
 * for the credential's scope; the periodic run belongs on a workstation or ops
 * box that publishes the derived JSON, never on the droplet.
 *
 * The export is written per project, one file per pipeline, mirroring what was
 * already on disk so an existing export folder can be pointed at directly.
 */
class PullDigibeePipelines
{
    public function __construct(private readonly DigibeectlClient $client) {}

    /**
     * @param  Closure(string): void|null  $onPipeline  progress, for the command
     * @return array{projects: int, pipelines: int, failures: list<string>}
     */
    public function handle(?Closure $onPipeline = null): array
    {
        $disk = Storage::disk('local');
        $root = trim((string) config('services.digibee.pipelines_dir'), '/');

        $projects = 0;
        $pipelines = 0;
        $failures = [];

        foreach ($this->client->projects() as $project) {
            $name = (string) ($project['name'] ?? '');
            $id = (string) ($project['id'] ?? '');

            // `.ainative-drafts` and friends: scratch space the platform keeps,
            // not integrations anybody wrote.
            if ($name === '' || $id === '' || str_starts_with($name, '.')) {
                continue;
            }

            $projects++;

            foreach ($this->client->pipelines($id) as $pipeline) {
                $pipelineId = $pipeline['latest']['_id'] ?? null;
                $pipelineName = $pipeline['_id']['name'] ?? null;

                if (! is_string($pipelineId) || ! is_string($pipelineName)) {
                    continue;
                }

                if ($onPipeline !== null) {
                    $onPipeline("{$name}/{$pipelineName}");
                }

                try {
                    $document = $this->client->flowspec($pipelineId);
                } catch (\Throwable $e) {
                    // One pipeline the CLI refuses must not end an export of
                    // 182 of them — the same reasoning as a failed page in the
                    // docs sync.
                    $failures[] = "{$name}/{$pipelineName}: " . $e->getMessage();

                    continue;
                }

                $disk->put(
                    "{$root}/{$name}/{$pipelineName}.json",
                    (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                );

                $pipelines++;
            }
        }

        return ['projects' => $projects, 'pipelines' => $pipelines, 'failures' => $failures];
    }
}
