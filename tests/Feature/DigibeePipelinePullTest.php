<?php

use App\Actions\Digibee\PullDigibeePipelines;
use App\Support\Digibee\DigibeectlClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

/** A digibeectl that answers from memory, and can be told to fail on one pipeline. */
function fakeDigibeectl(array $pipelines, ?string $failOn = null): DigibeectlClient
{
    return new class($pipelines, $failOn) extends DigibeectlClient
    {
        public function __construct(private array $pipelines, private ?string $failOn) {}

        public function available(): bool
        {
            return true;
        }

        public function projects(): array
        {
            return [['id' => 'p1', 'name' => 'Pedido'], ['id' => 'hidden', 'name' => '.ainative-drafts']];
        }

        public function pipelines(string $projectId): array
        {
            return array_map(fn (string $name) => [
                '_id' => ['name' => $name], 'latest' => ['_id' => "id-{$name}"],
            ], $this->pipelines);
        }

        public function flowspec(string $pipelineId): array
        {
            if ($this->failOn !== null && $pipelineId === "id-{$this->failOn}") {
                throw new RuntimeException('digibeectl exited with 1');
            }

            return ['meta' => [], 'flowSpec' => ['disconnected-root:1' => []]];
        }
    };
}

it('skips a project the platform keeps for itself', function () {
    Storage::fake('local');

    $report = (new PullDigibeePipelines(fakeDigibeectl(['a'])))->handle();

    expect($report['projects'])->toBe(1)
        ->and(Storage::disk('local')->exists('digibee-pipelines/Pedido/a.json'))->toBeTrue();
});

it('removes the export of a pipeline the tenant no longer has', function () {
    Storage::fake('local');
    Storage::disk('local')->put('digibee-pipelines/Pedido/removida.json', '{"meta":{},"flowSpec":{}}');

    // The export is written by path, so a deleted pipeline is simply never
    // overwritten again — and IndexPipelineVocabulary walks the directory with
    // no manifest to filter by, so it keeps teaching from a pipeline that
    // stopped existing. Two June files were still being indexed in September.
    $report = (new PullDigibeePipelines(fakeDigibeectl(['a'])))->handle();

    expect($report['pruned'])->toBe(['digibee-pipelines/Pedido/removida.json'])
        ->and(Storage::disk('local')->exists('digibee-pipelines/Pedido/removida.json'))->toBeFalse();
});

it('prunes nothing at all when any pipeline failed to fetch', function () {
    Storage::fake('local');
    Storage::disk('local')->put('digibee-pipelines/Pedido/antiga.json', '{"meta":{},"flowSpec":{}}');

    // A pipeline this run could not reach is indistinguishable from one that
    // was deleted, and deleting the corpus because the network blinked is far
    // worse than carrying a stale file another week.
    $report = (new PullDigibeePipelines(fakeDigibeectl(['a', 'quebrada'], failOn: 'quebrada')))->handle();

    expect($report['failures'])->toHaveCount(1)
        ->and($report['pruned'])->toBe([])
        ->and(Storage::disk('local')->exists('digibee-pipelines/Pedido/antiga.json'))->toBeTrue();
});
