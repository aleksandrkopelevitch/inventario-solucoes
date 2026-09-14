<?php

use App\Actions\Digibee\AssessPromotion;
use App\Enums\HealingVerdict;
use App\Support\Digibee\Healing\HealingReport;
use Illuminate\Support\Facades\Http;

/**
 * Bloco G — a verdict, not an action.
 *
 * Nothing here can deploy, and no test needs to guard against it: the action
 * has no deploy collaborator at all. What is asserted is the REASON in each
 * case, because the reason is the product — somebody is about to click promote
 * in the Digibee panel on the strength of it.
 */
function withReadinessConfig(array $overrides = []): void
{
    config()->set('services.digibee.design', array_merge([
        'endpoint'                => 'https://core.example.test',
        'realm'                   => 'leomadeiras',
        'jwt'                     => 'header.payload.signature',
        'apikey'                  => 'a-key',
        'config_path'             => '',
        'timeout'                 => 30,
        'retries'                 => 1,
        'retry_sleep'             => 0,
        'runtime_hosts'           => ['test' => 'https://test.example.test', 'prod' => 'https://api.example.test'],
        'deployable_environments' => ['test'],
    ], $overrides));
}

function readinessDocument(string $message = 'oi'): array
{
    return ['meta' => [], 'flowSpec' => ['disconnected-root:11111111-1111-4111-8111-111111111111' => [
        [
            'id'       => '22222222-2222-4222-8222-222222222222',
            'type'     => 'connector',
            'name'     => 'log-connector',
            'stepName' => 'Log',
            'params'   => ['logLevel' => 'INFO', 'message' => $message],
        ],
    ]]];
}

/** What the realm answers — the PLATFORM shape, rooted at `start`, no `meta`. */
function readinessStored(string $message = 'oi', int $minor = 2): array
{
    return [
        'id'           => 'pid-1',
        'name'         => 'meu-pipeline',
        'versionMajor' => 1,
        'versionMinor' => $minor,
        'triggerSpec'  => ['type' => 'rest'],
        'flowSpec'     => ['start' => [
            [
                'id'       => '22222222-2222-4222-8222-222222222222',
                'type'     => 'connector',
                'name'     => 'log-connector',
                'stepName' => 'Log',
                'params'   => ['logLevel' => 'INFO', 'message' => $message],
            ],
        ]],
    ];
}

function readinessRealmHolding(array $pipelines): void
{
    // Registered per test: `Http::fake()` APPENDS stub callbacks rather than
    // replacing them, so a `'*'` set in a `beforeEach` matches first and the
    // one inside a test never runs.
    Http::fake(['*' => Http::response($pipelines)]);
}

function readinessEvidence(
    HealingVerdict $verdict = HealingVerdict::Green,
    ?array $document = null,
    bool $withDocument = true,
): HealingReport {
    return new HealingReport(
        pipelineName: 'meu-pipeline',
        environment: 'test',
        verdict: $verdict,
        document: $withDocument ? ($document ?? readinessDocument()) : null,
        endpoint: 'https://test.example.test/pipeline/leomadeiras/v1/meu-pipeline',
    );
}

beforeEach(fn () => withReadinessConfig());

it('says a green pipeline that still matches what was tested is ready', function () {
    readinessRealmHolding([readinessStored()]);

    $readiness = app(AssessPromotion::class)->handle(readinessEvidence());

    expect($readiness->ready)->toBeTrue()
        ->and($readiness->blockers)->toBe([])
        ->and($readiness->testedIn)->toBe('test');
});

it('names the version to promote, because the panel lists rows', function () {
    readinessRealmHolding([readinessStored(minor: 5)]);

    $readiness = app(AssessPromotion::class)->handle(readinessEvidence());

    // A verdict that does not say WHICH row to click is a verdict nobody can
    // act on.
    expect($readiness->version)->toBe('v1.5')
        ->and(implode(' ', $readiness->notes))->toContain('Promova pela Digibee');
});

it('blocks evidence that is not green', function () {
    readinessRealmHolding([readinessStored()]);

    expect(implode(' ', app(AssessPromotion::class)->handle(readinessEvidence(HealingVerdict::StillFailing))->blockers))
        ->toContain('A evidência não é verde');
});

it('says when the evidence was not even about the pipeline', function () {
    // A wall of 401s fails no case and passes none — the tally alone would look
    // like nothing is wrong.
    readinessRealmHolding([readinessStored()]);

    expect(implode(' ', app(AssessPromotion::class)->handle(readinessEvidence(HealingVerdict::RefusedAtTheDoor))->blockers))
        ->toContain('não chega a ser uma conclusão sobre o pipeline');
});

it('blocks when the stored pipeline is no longer the one that was tested', function () {
    // The check that earns its keep, and it earns MORE now that a human does
    // the promoting: the canvas writes directly, and the panel will happily
    // promote whatever is there.
    readinessRealmHolding([readinessStored('alterado no canvas')]);

    expect(implode(' ', app(AssessPromotion::class)->handle(readinessEvidence())->blockers))
        ->toContain('não é mais o que foi testado');
});

it('does not read the generator shape as drift from the stored one', function () {
    // The tested document roots at `disconnected-root:<uuid>` and carries a
    // canvas `meta`; the stored one roots at `start` and has neither. Comparing
    // them raw would block every correct promotion.
    readinessRealmHolding([readinessStored()]);

    expect(app(AssessPromotion::class)->handle(readinessEvidence())->ready)->toBeTrue();
});

it('blocks evidence carrying no document, since nothing ties it to what is stored', function () {
    readinessRealmHolding([readinessStored()]);

    expect(implode(' ', app(AssessPromotion::class)->handle(readinessEvidence(withDocument: false))->blockers))
        ->toContain('não carrega o documento que foi testado');
});

it('blocks a pipeline the realm does not have', function () {
    readinessRealmHolding([]);

    $readiness = app(AssessPromotion::class)->handle(readinessEvidence());

    expect(implode(' ', $readiness->blockers))->toContain('Nenhum pipeline chamado')
        ->and($readiness->version)->toBeNull();
});

it('reports every blocker, not just the first', function () {
    readinessRealmHolding([readinessStored('alterado no canvas')]);

    $blockers = app(AssessPromotion::class)->handle(readinessEvidence(HealingVerdict::Unproven))->blockers;

    // Verdict and drift — somebody deciding whether to promote gets both at
    // once rather than fixing one and discovering the other.
    expect($blockers)->toHaveCount(2);
});

it('asks the realm nothing when the evidence carries no document to compare', function () {
    Http::fake();

    app(AssessPromotion::class)->handle(readinessEvidence(withDocument: false));

    Http::assertNothingSent();
});
