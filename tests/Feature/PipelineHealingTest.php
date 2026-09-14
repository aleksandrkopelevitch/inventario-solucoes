<?php

use App\Actions\Digibee\DeployPipeline;
use App\Actions\Digibee\IngestFlowspec;
use App\Actions\Digibee\RunPipelineTestSuite;
use App\Actions\Flowspec\BuildPipelineTestMatrix;
use App\Enums\DeploymentStatus;
use App\Enums\HealingVerdict;
use App\Services\Digibee\PipelineHealingService;
use App\Services\Flowspec\FlowspecPromptBuilder;
use App\Support\Digibee\DeploymentReport;
use App\Support\Digibee\IngestionReport;
use App\Support\Digibee\Testing\CaseResult;
use App\Support\Digibee\Testing\EndpointCredential;
use App\Support\Digibee\Testing\PipelineTestCase;
use App\Support\Digibee\Testing\PipelineTestSuite;
use App\Support\Digibee\Testing\StatusExpectation;
use App\Support\Digibee\Testing\SuiteRun;
use App\Support\Digibee\Testing\TestCaseCategory;
use App\Exceptions\DigibeeApiException;
use App\Support\Digibee\TriggerSpec;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Bloco F — the loop, with the platform and the model both faked.
 *
 * What is being tested is the JUDGEMENT, not the HTTP: which endings hand the
 * model evidence and which refuse to. Faking at the action boundary rather
 * than at `Http::fake()` is deliberate — a round that reaches the runner sends
 * malformed payloads at a real address, so the thing worth proving here is
 * that most endings never get that far.
 */

/** A round's worth of platform answers, scripted. */
function healingDoubles(array $deployments, array $runs, ?array $ingestions = null): array
{
    $ingest = new class($ingestions) extends IngestFlowspec
    {
        public array $createFlags = [];

        public function __construct(private ?array $scripted) {}

        public function handle(
            array $document,
            string $pipelineName,
            ?TriggerSpec $trigger = null,
            bool $create = false,
            bool $replaceTrigger = false,
            bool $dryRun = false,
        ): IngestionReport {
            $this->createFlags[] = $create;

            if ($this->scripted === null) {
                return new IngestionReport(pipelineName: $pipelineName, wrote: true, verified: true);
            }

            $next = array_shift($this->scripted);

            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        }
    };

    $deploy = new class($deployments) extends DeployPipeline
    {
        public function __construct(private array $scripted) {}

        public function handle(
            string $pipelineName,
            string $environment = 'test',
            string $size = 'SMALL',
            bool $redeploy = true,
            int $timeoutSeconds = 300,
            bool $dryRun = false,
        ): DeploymentReport {
            return array_shift($this->scripted);
        }
    };

    $matrix = new class extends BuildPipelineTestMatrix
    {
        public function __construct() {}

        public function handle(array $document, string $pipelineName, string $environment = 'test'): PipelineTestSuite
        {
            return new PipelineTestSuite(
                name: 'suite', pipelineName: $pipelineName, environment: $environment,
                cases: [], versionMajor: 1,
            );
        }
    };

    $runner = new class($runs) extends RunPipelineTestSuite
    {
        public function __construct(private array $scripted) {}

        public function handle(
            PipelineTestSuite $suite,
            ?EndpointCredential $credential = null,
            ?string $endpoint = null,
        ): SuiteRun {
            return array_shift($this->scripted);
        }
    };

    return [$ingest, $deploy, $matrix, $runner];
}

/** The service with the platform faked and the model answering a script. */
function healingService(array $deployments, array $runs, array $answers = [], ?array $ingestions = null): PipelineHealingService
{
    [$ingest, $deploy, $matrix, $runner] = healingDoubles($deployments, $runs, $ingestions);

    return new class($ingest, $deploy, $matrix, $runner, $answers) extends PipelineHealingService
    {
        public int $calls = 0;

        public function __construct($ingest, $deploy, $matrix, $runner, private array $answers)
        {
            parent::__construct($ingest, $deploy, $matrix, $runner, app(FlowspecPromptBuilder::class));
        }

        protected function prompt(string $prompt): AgentResponse
        {
            $this->calls++;

            return new AgentResponse(
                'fake',
                (string) array_shift($this->answers),
                new Usage(10, 20),
                new Meta('anthropic', 'claude-sonnet-5'),
            );
        }
    };
}

function healingDocument(string $message = 'oi'): array
{
    return ['meta' => [], 'flowSpec' => ['disconnected-root:a' => [
        ['id' => 'b', 'type' => 'connector', 'name' => 'log-connector', 'params' => ['message' => $message]],
    ]]];
}

function liveDeployment(): DeploymentReport
{
    return new DeploymentReport(
        pipelineName: 'p', environment: 'test', pipelineId: 'pid', deploymentId: 'did',
        status: DeploymentStatus::Active, endpoint: 'https://test.example.test/pipeline/r/v1/p',
        engine: ['replicas' => '1/1', 'errors' => 0, 'oom' => 0, 'lastError' => null],
        deployed: true, waitedSeconds: 10,
    );
}

function healingCase(string $name, TestCaseCategory $category): PipelineTestCase
{
    return new PipelineTestCase(name: $name, category: $category, expects: StatusExpectation::ok());
}

function healingRun(array $results, array $skipped = [], bool $authenticated = true): SuiteRun
{
    return new SuiteRun(
        suite: new PipelineTestSuite(name: 's', pipelineName: 'p', environment: 'test', cases: [], versionMajor: 1),
        url: 'https://test.example.test/pipeline/r/v1/p',
        results: $results,
        skipped: $skipped,
        authenticated: $authenticated,
    );
}

function passingHappyPath(): CaseResult
{
    return new CaseResult(healingCase('Caminho feliz', TestCaseCategory::HappyPath), 200, true);
}

function failingCase(string $name = 'Corpo malformado'): CaseResult
{
    return new CaseResult(healingCase($name, TestCaseCategory::Contract), 500, false);
}

/*
|--------------------------------------------------------------------------
| Green, and the one ending that is not a verdict about the pipeline
|--------------------------------------------------------------------------
*/

it('stops green when the battery passes and the happy path ran', function () {
    $service = healingService([liveDeployment()], [healingRun([passingHappyPath()])]);

    $report = $service->heal(healingDocument(), 'p');

    expect($report->verdict)->toBe(HealingVerdict::Green)
        ->and($report->healed())->toBeTrue()
        ->and($report->rounds)->toHaveCount(1)
        ->and($service->calls)->toBe(0);       // nothing to correct, nothing asked
});

it('does not correct a suite that merely failed to prove anything', function () {
    // Nothing failed, but the happy path was blocked — so there is no failure
    // to hand the model, and asking for one means asking it to rewrite a
    // pipeline that may well be right.
    $run = healingRun([], [healingCase('Caminho feliz', TestCaseCategory::HappyPath)]);
    $service = healingService([liveDeployment()], [$run]);

    $report = $service->heal(healingDocument(), 'p');

    expect($report->verdict)->toBe(HealingVerdict::Unproven)
        ->and($report->verdict->judgedThePipeline())->toBeTrue()
        ->and($service->calls)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The endings that must never reach the model
|--------------------------------------------------------------------------
*/

it('refuses to correct a wall of 401s, which is the door and not the pipeline', function () {
    $run = healingRun([
        new CaseResult(healingCase('A', TestCaseCategory::Contract), 401, false),
        new CaseResult(healingCase('B', TestCaseCategory::Contract), 401, false),
    ], authenticated: false);

    $service = healingService([liveDeployment()], [$run]);
    $report = $service->heal(healingDocument(), 'p');

    expect($report->verdict)->toBe(HealingVerdict::RefusedAtTheDoor)
        ->and($report->verdict->judgedThePipeline())->toBeFalse()
        ->and($service->calls)->toBe(0);
});

it('refuses to correct when every case came back 404', function () {
    $run = healingRun([
        new CaseResult(healingCase('A', TestCaseCategory::Contract), 404, false),
        new CaseResult(healingCase('B', TestCaseCategory::Contract), 404, false),
    ]);

    $service = healingService([liveDeployment()], [$run]);
    $report = $service->heal(healingDocument(), 'p');

    expect($report->verdict)->toBe(HealingVerdict::NotAnswering)
        ->and($service->calls)->toBe(0);
});

it('refuses to correct a deploy that never settled', function () {
    $starting = new DeploymentReport(
        pipelineName: 'p', environment: 'test', status: DeploymentStatus::Starting,
        deployed: true, waitedSeconds: 300,
    );

    $service = healingService([$starting], []);
    $report = $service->heal(healingDocument(), 'p');

    expect($report->verdict)->toBe(HealingVerdict::Unsettled)
        ->and($service->calls)->toBe(0);
});

it('refuses to correct a deploy the platform refused', function () {
    $refused = new DeploymentReport(
        pipelineName: 'p', environment: 'test',
        errors: ['Ambiente "prod" não está em deployable_environments.'],
    );

    $service = healingService([$refused], []);
    $report = $service->heal(healingDocument(), 'p');

    expect($report->verdict)->toBe(HealingVerdict::Refused)
        ->and($service->calls)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| What DOES earn a correction
|--------------------------------------------------------------------------
*/

it('corrects from failed cases and stops when the next round is green', function () {
    $service = healingService(
        deployments: [liveDeployment(), liveDeployment()],
        runs: [healingRun([failingCase()]), healingRun([passingHappyPath()])],
        answers: ['```json' . json_encode(healingDocument('corrigido')) . '```'],
    );

    $report = $service->heal(healingDocument(), 'p');

    expect($report->verdict)->toBe(HealingVerdict::Green)
        ->and($report->rounds)->toHaveCount(2)
        ->and($service->calls)->toBe(1)
        ->and($report->document)->toBe(healingDocument('corrigido'))
        ->and($report->rounds[0]->evidence)->not->toBeEmpty();
});

it('treats an engine that came up broken as evidence about the document', function () {
    $broken = new DeploymentReport(
        pipelineName: 'p', environment: 'test', status: DeploymentStatus::Error,
        engine: ['replicas' => '0/1', 'errors' => 9, 'oom' => 0, 'lastError' => 'Pipeline Configuration is invalid'],
        deployed: true, waitedSeconds: 12,
    );

    $service = healingService(
        deployments: [$broken, liveDeployment()],
        runs: [healingRun([passingHappyPath()])],
        answers: ['```json' . json_encode(healingDocument('corrigido')) . '```'],
    );

    $report = $service->heal(healingDocument(), 'p');

    expect($report->verdict)->toBe(HealingVerdict::Green)
        ->and($service->calls)->toBe(1)
        ->and($report->rounds[0]->evidence[1] ?? '')->toContain('Pipeline Configuration is invalid');
});

it('feeds a refused write back, because those errors are about the document', function () {
    $refused = new IngestionReport(pipelineName: 'p', errors: ['Step sem id UUID v4.'], documentRejected: true);

    $service = healingService(
        deployments: [liveDeployment()],
        runs: [healingRun([passingHappyPath()])],
        answers: ['```json' . json_encode(healingDocument('corrigido')) . '```'],
        ingestions: [$refused, new IngestionReport(pipelineName: 'p', wrote: true, verified: true)],
    );

    $report = $service->heal(healingDocument(), 'p');

    expect($report->rounds[0]->verdict)->toBe(HealingVerdict::NotIngested)
        ->and($report->rounds[0]->reached())->toBe('escrita')
        ->and($report->verdict)->toBe(HealingVerdict::Green)
        ->and($service->calls)->toBe(1);
});

it('does not re-prompt a write the REALM refused, only one the document earned', function () {
    // The default pipeline name on the new screen is a slug of the chat title,
    // which usually names no pipeline at all — so "no pipeline called X exists"
    // is the routine refusal there. Handing it to the model spends an attempt
    // asking it to fix a flowSpec that is fine, and ends the run confused.
    $refused = new IngestionReport(
        pipelineName: 'p',
        errors: ['Nenhum pipeline chamado "p" existe no realm.'],
    );

    $service = healingService(
        deployments: [],
        runs: [],
        answers: ['```json' . json_encode(healingDocument('corrigido')) . '```'],
        ingestions: [$refused],
    );

    $report = $service->heal(healingDocument(), 'p');

    expect($report->verdict)->toBe(HealingVerdict::NotWritable)
        ->and($report->verdict->judgedThePipeline())->toBeFalse()
        ->and($service->calls)->toBe(0)               // nothing asked of the model
        ->and($report->deploys())->toBe(0)
        ->and($report->finalEvidence()[0])->toContain('existe no realm');
});

/*
|--------------------------------------------------------------------------
| Knowing when to stop
|--------------------------------------------------------------------------
*/

it('stops when the model hands back the same document', function () {
    $service = healingService(
        deployments: [liveDeployment()],
        runs: [healingRun([failingCase()])],
        answers: ['```json' . json_encode(healingDocument()) . '```'],
    );

    $report = $service->heal(healingDocument(), 'p');

    expect($report->verdict)->toBe(HealingVerdict::Stuck)
        ->and($service->calls)->toBe(1)
        ->and($report->deploys())->toBe(1);   // the round that learned something, and no more
});

it('sees through a document whose keys were merely reordered', function () {
    $shuffled = ['flowSpec' => healingDocument()['flowSpec'], 'meta' => []];

    $service = healingService(
        deployments: [liveDeployment()],
        runs: [healingRun([failingCase()])],
        answers: ['```json' . json_encode($shuffled) . '```'],
    );

    expect($service->heal(healingDocument(), 'p')->verdict)->toBe(HealingVerdict::Stuck);
});

it('stops when the model answers with no document at all', function () {
    $service = healingService(
        deployments: [liveDeployment()],
        runs: [healingRun([failingCase()])],
        answers: ['Não consigo corrigir sem saber a URL do serviço.'],
    );

    expect($service->heal(healingDocument(), 'p')->verdict)->toBe(HealingVerdict::Stuck);
});

it('gives up as still-failing when the rounds run out', function () {
    $service = healingService(
        deployments: [liveDeployment(), liveDeployment()],
        runs: [healingRun([failingCase('A')]), healingRun([failingCase('B')])],
        answers: ['```json' . json_encode(healingDocument('dois')) . '```'],
    );

    $report = $service->heal(healingDocument(), 'p', maxRounds: 2);

    expect($report->verdict)->toBe(HealingVerdict::StillFailing)
        ->and($report->rounds)->toHaveCount(2)
        ->and($report->document)->toBe(healingDocument('dois'))   // what is deployed, not what failed least
        ->and($report->finalEvidence())->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| What the run costs, and what it says about itself
|--------------------------------------------------------------------------
*/

it('only asks to CREATE the pipeline on the first round', function () {
    [$ingest, $deploy, $matrix, $runner] = healingDoubles(
        [liveDeployment(), liveDeployment()],
        [healingRun([failingCase()]), healingRun([passingHappyPath()])],
    );

    $service = new class($ingest, $deploy, $matrix, $runner) extends PipelineHealingService
    {
        public function __construct($i, $d, $m, $r)
        {
            parent::__construct($i, $d, $m, $r, app(FlowspecPromptBuilder::class));
        }

        protected function prompt(string $prompt): AgentResponse
        {
            return new AgentResponse('f', '```json' . json_encode(healingDocument('dois')) . '```', new Usage(1, 1), new Meta('a', 'm'));
        }
    };

    $service->heal(healingDocument(), 'p', create: true);

    // Round two writes into the pipeline round one created; asking again is
    // asking for a second pipeline with the same name, which this platform
    // answers by making one.
    expect($ingest->createFlags)->toBe([true, false]);
});

it('says out loud how many deployments it left behind', function () {
    $service = healingService([liveDeployment()], [healingRun([passingHappyPath()])]);

    $report = $service->heal(healingDocument(), 'p');

    expect($report->deploys())->toBe(1)
        ->and(implode(' ', $report->warnings))->toContain('só o canvas remove');
});

it('warns that a run which never judged the pipeline concluded nothing about it', function () {
    $refused = new DeploymentReport(pipelineName: 'p', environment: 'test', errors: ['Access denied']);
    $report = healingService([$refused], [])->heal(healingDocument(), 'p');

    expect(implode(' ', $report->warnings))->toContain('Nada aqui é uma conclusão sobre o pipeline');
});

it('ends as not-writable when the platform refuses the write, instead of throwing', function () {
    // Measured against the realm on 2026-09-14: deploying PUBLISHES a
    // pipeline, so round two cannot write into what round one deployed. A
    // multi-round loop that aborts by exception here reports nothing at all
    // about the rounds that already ran — and no rewrite the model produces
    // can get past it, so this must never be re-prompted.
    $refusal = new DigibeeApiException(
        'POST /design/realms/leomadeiras/pipelines answered 409 (unexpected status): '
        . 'You cannot update a pipeline that is not on draft mode'
    );

    $service = healingService(
        deployments: [liveDeployment()],
        runs: [healingRun([failingCase()])],
        answers: ['```json' . json_encode(healingDocument('corrigido')) . '```'],
        ingestions: [new IngestionReport(pipelineName: 'p', wrote: true, verified: true), $refusal],
    );

    $report = $service->heal(healingDocument(), 'p', maxRounds: 3);

    expect($report->verdict)->toBe(HealingVerdict::NotWritable)
        ->and($report->rounds)->toHaveCount(2)
        ->and($report->rounds[1]->reached())->toBe('nada')
        ->and($report->verdict->judgedThePipeline())->toBeFalse()
        ->and($service->calls)->toBe(1)          // the one correction it did ask for
        ->and($report->finalEvidence()[0])->toContain('not on draft mode');
});
