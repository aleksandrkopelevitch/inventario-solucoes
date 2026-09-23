<?php

use App\Console\Commands\HealPipelineCommand;
use App\Enums\DigibeeTriggerKind;
use App\Enums\HealingVerdict;
use App\Services\Digibee\PipelineHealingService;
use App\Support\Digibee\Healing\HealingReport;
use App\Support\Digibee\Testing\EndpointCredential;
use App\Support\Digibee\TriggerSpec;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/**
 * The console half of the lifecycle — and the half nothing covered.
 *
 * `--cron` and `--event` carry the two values `SynthesizeTriggerSpec` refuses
 * to invent, so a `--trigger=scheduler` without its cron comes back incomplete
 * and the run stops as `NotWritable`: the two kinds had no reachable path at
 * all before those options existed, and no test would have noticed them going
 * away again. Nothing here reaches the realm — the healing service is a double
 * that records the `TriggerSpec` it was handed and returns.
 */
function healCommandDouble(stdClass $seen): PipelineHealingService
{
    return new class($seen) extends PipelineHealingService
    {
        public function __construct(private stdClass $seen) {}

        public function heal(
            array $document,
            string $pipelineName,
            string $environment = 'test',
            ?TriggerSpec $trigger = null,
            ?EndpointCredential $credential = null,
            bool $create = false,
            ?int $maxRounds = null,
            ?callable $onRound = null,
            ?int $deployTimeoutSeconds = null,
        ): HealingReport {
            $this->seen->trigger = $trigger;
            $this->seen->create = $create;

            return new HealingReport(
                pipelineName: $pipelineName,
                environment: $environment,
                verdict: HealingVerdict::Green,
                document: $document,
            );
        }
    };
}

/** A minimal `{meta, flowSpec}` on disk, which is what `--file` reads. */
function healCommandDocument(): string
{
    $path = tempnam(sys_get_temp_dir(), 'flowspec') . '.json';
    file_put_contents($path, json_encode([
        'meta'     => ['name' => 'probe'],
        'flowSpec' => ['disconnected-root:a' => []],
    ]));

    return $path;
}

beforeEach(function () {
    $this->seen = new stdClass;
    $this->seen->trigger = 'never called';
    $this->document = healCommandDocument();
    $this->app->instance(PipelineHealingService::class, healCommandDouble($this->seen));
});

afterEach(function () {
    @unlink($this->document);
});

it('writes a scheduler trigger carrying the cron the operator typed', function () {
    $this->artisan(HealPipelineCommand::class, [
        'name'      => 'zfl-agendado',
        '--file'    => $this->document,
        '--trigger' => 'scheduler',
        '--cron'    => '0 0 6 ? * * *',
        '--force'   => true,
    ])->assertSuccessful();

    expect($this->seen->trigger)->toBeInstanceOf(TriggerSpec::class)
        ->and($this->seen->trigger->kind)->toBe(DigibeeTriggerKind::Scheduler)
        ->and($this->seen->trigger->spec['cronExpression'] ?? null)->toBe('0 0 6 ? * * *')
        // Complete: nothing was left for the platform to refuse.
        ->and($this->seen->trigger->missing)->toBe([]);
});

it('writes an event trigger carrying the event name the operator typed', function () {
    $this->artisan(HealPipelineCommand::class, [
        'name'      => 'zfl-evento',
        '--file'    => $this->document,
        '--trigger' => 'event',
        '--event'   => 'pedido.criado',
        '--force'   => true,
    ])->assertSuccessful();

    expect($this->seen->trigger->kind)->toBe(DigibeeTriggerKind::Event)
        ->and($this->seen->trigger->missing)->toBe([]);
});

it('reports the cron as missing rather than inventing one', function () {
    // The whole reason the option exists: a guessed cron does not fail, it
    // runs at the wrong time. So the spec comes back incomplete and says so.
    $this->artisan(HealPipelineCommand::class, [
        'name'      => 'zfl-agendado',
        '--file'    => $this->document,
        '--trigger' => 'scheduler',
        '--force'   => true,
    ])->assertSuccessful();

    expect($this->seen->trigger->missing)->not->toBe([])
        ->and($this->seen->trigger->spec)->not->toHaveKey('cronExpression');
});

it('refuses a trigger kind the platform does not have, before writing anything', function () {
    $this->artisan(HealPipelineCommand::class, [
        'name'      => 'zfl-teste',
        '--file'    => $this->document,
        '--trigger' => 'carrier-pigeon',
        '--force'   => true,
    ])->assertFailed();

    expect($this->seen->trigger)->toBe('never called');
});

it('leaves the pipeline own trigger alone when none is asked for', function () {
    $this->artisan(HealPipelineCommand::class, [
        'name'    => 'zfl-existente',
        '--file'  => $this->document,
        '--force' => true,
    ])->assertSuccessful();

    expect($this->seen->trigger)->toBeNull()
        ->and($this->seen->create)->toBeFalse();
});
