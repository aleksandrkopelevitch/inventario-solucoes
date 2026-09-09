<?php

use App\Actions\Flowspec\SynthesizeTriggerSpec;
use App\Enums\DigibeeTriggerAuth;
use App\Enums\DigibeeTriggerKind;
use App\Support\Digibee\TriggerSpec;

/**
 * Block C — synthesizing the `triggerSpec` the generator never produces.
 *
 * Pure local logic, no credential and no route: every expectation here is a
 * claim about the shape the tenant's own 183 stored trigger specs have, or
 * about something this deliberately refuses to invent.
 */
function synthesize(DigibeeTriggerKind $kind, array $options = []): TriggerSpec
{
    return app(SynthesizeTriggerSpec::class)->handle($kind, $options);
}

/*
|--------------------------------------------------------------------------
| Web protocols
|--------------------------------------------------------------------------
*/

it('builds a rest trigger with the keys every stored web trigger carries', function () {
    $trigger = synthesize(DigibeeTriggerKind::Rest);

    expect($trigger->usable())->toBeTrue()
        ->and($trigger->spec['type'])->toBe('rest')
        ->and($trigger->spec['name'])->toBe('rest')
        ->and($trigger->spec['external'])->toBeTrue()
        ->and($trigger->spec['internal'])->toBeFalse()
        ->and($trigger->spec['mtls'])->toBeFalse()
        ->and($trigger->spec['addCors'])->toBeFalse()
        ->and($trigger->spec['requestSizeLimit'])->toBe(5)
        ->and($trigger->spec['timeout'])->toBe(30000)
        ->and($trigger->spec['methods'])->toBe(['POST']);
});

it('defaults a rest trigger to api key and an http one to basic auth, as the tenant does', function () {
    $rest = synthesize(DigibeeTriggerKind::Rest);
    $http = synthesize(DigibeeTriggerKind::Http);

    expect($rest->spec['keyAuth'])->toBeTrue()
        ->and($rest->spec['basicAuth'])->toBeFalse()
        ->and($http->spec['basicAuth'])->toBeTrue()
        ->and($http->spec['keyAuth'])->toBeFalse()
        ->and(implode(' ', $rest->assumptions))->toContain('Autenticação assumida');
});

it('emits exactly one auth flag as true', function (DigibeeTriggerAuth $auth) {
    $trigger = synthesize(DigibeeTriggerKind::Rest, ['auth' => $auth]);
    $flags = array_filter([
        $trigger->spec['basicAuth'],
        $trigger->spec['keyAuth'],
        $trigger->spec['jwt'],
    ]);

    // Two true flags is a question about precedence nobody answered.
    expect(count($flags))->toBe($auth === DigibeeTriggerAuth::None ? 0 : 1);
})->with([
    DigibeeTriggerAuth::BasicAuth,
    DigibeeTriggerAuth::KeyAuth,
    DigibeeTriggerAuth::Jwt,
    DigibeeTriggerAuth::None,
]);

it('never leaves an endpoint open by default, and says so when asked to', function () {
    $default = synthesize(DigibeeTriggerKind::Http);
    $open = synthesize(DigibeeTriggerKind::Http, ['auth' => DigibeeTriggerAuth::None]);

    expect($default->auth->requiresCredential())->toBeTrue()
        ->and(implode(' ', $open->assumptions))->toContain('SEM autenticação');
});

it('omits uris unless they were given, and marks the spec advanced when they were', function () {
    $default = synthesize(DigibeeTriggerKind::Rest);
    $explicit = synthesize(DigibeeTriggerKind::Rest, ['uris' => ['/meu-pipeline/consulta']]);

    // 32 of the 46 stored rest specs carry no `uris`: the platform's default
    // path is a real answer, and an invented one publishes an endpoint at an
    // address nobody agreed on.
    expect($default->spec)->not->toHaveKey('uris')
        ->and($default->spec['advanced'])->toBeFalse()
        ->and($explicit->spec['uris'])->toBe(['/meu-pipeline/consulta'])
        ->and($explicit->spec['advanced'])->toBeTrue();
});

it('defaults content types to JSON even though the corpus is mostly XML', function () {
    $trigger = synthesize(DigibeeTriggerKind::Http);

    expect($trigger->spec['requestContentTypes'])->toBe(['application/json'])
        ->and($trigger->spec['responseContentTypes'])->toBe(['application/json'])
        ->and(implode(' ', $trigger->assumptions))->toContain('legado SOAP');
});

it('does not write rest-only keys onto an http trigger', function () {
    $trigger = synthesize(DigibeeTriggerKind::Http, ['uris' => ['/nao-vale-aqui']]);

    // `uris` is not a key of any of the 66 stored http specs.
    expect($trigger->spec)->not->toHaveKey('uris')
        ->and($trigger->spec['routes'])->toBe([]);
});

it('gives an http-file trigger the upload keys and a ceiling that fits a file', function () {
    $trigger = synthesize(DigibeeTriggerKind::HttpFile);

    expect($trigger->spec['bodyUpload'])->toBeTrue()
        ->and($trigger->spec['formDataUpload'])->toBeFalse()
        ->and($trigger->spec['bodyUploadContentTypes'])->toBe(['application/json'])
        ->and($trigger->spec['requestSizeLimit'])->toBe(100);
});

it('uppercases the methods it is handed', function () {
    expect(synthesize(DigibeeTriggerKind::Rest, ['methods' => ['get', 'post']])->spec['methods'])
        ->toBe(['GET', 'POST']);
});

/*
|--------------------------------------------------------------------------
| Scheduler and event — what it refuses to invent
|--------------------------------------------------------------------------
*/

it('refuses to invent a cron expression', function () {
    $trigger = synthesize(DigibeeTriggerKind::Scheduler);

    // A guessed schedule does not fail: it runs, at the wrong hour, against
    // whatever the pipeline touches.
    expect($trigger->usable())->toBeFalse()
        ->and($trigger->spec)->not->toHaveKey('cronExpression')
        ->and(implode(' ', $trigger->missing))->toContain('cronExpression');
});

it('builds a scheduler once it has a cron, in the shape the tenant stores', function () {
    $trigger = synthesize(DigibeeTriggerKind::Scheduler, ['cron' => '0 */2 * ? * * *']);

    expect($trigger->usable())->toBeTrue()
        ->and($trigger->spec['cronExpression'])->toBe('0 */2 * ? * * *')
        ->and($trigger->spec['timeZoneId'])->toBe('America/Sao_Paulo')
        ->and($trigger->spec['concurrentScheduling'])->toBeFalse()
        ->and($trigger->spec['retries'])->toBe(0);
});

it('calls a scheduler custom-scheduler, never scheduler', function () {
    // Every other kind writes its type in `name`; a scheduler writes the
    // canvas preset, and `custom-scheduler` is the only one of the three that
    // claims nothing about the cron beside it.
    expect(synthesize(DigibeeTriggerKind::Scheduler, ['cron' => '0 0 * ? * *'])->spec['name'])
        ->toBe('custom-scheduler');
});

it('refuses to invent an event name', function () {
    $trigger = synthesize(DigibeeTriggerKind::Event);

    expect($trigger->usable())->toBeFalse()
        ->and(implode(' ', $trigger->missing))->toContain('eventName');
});

it('builds an event trigger once it has a name', function () {
    $trigger = synthesize(DigibeeTriggerKind::Event, ['eventName' => 'evt-insert-bq']);

    expect($trigger->usable())->toBeTrue()
        ->and($trigger->spec['eventName'])->toBe('evt-insert-bq')
        ->and($trigger->spec['expiration'])->toBe(600000);
});

/*
|--------------------------------------------------------------------------
| What the runner reads off it
|--------------------------------------------------------------------------
*/

it('reports whether the trigger gives the test runner anything to call', function () {
    expect(synthesize(DigibeeTriggerKind::Rest)->callability())
        ->toBe(['callable' => true, 'methods' => ['POST'], 'auth' => 'key'])
        ->and(synthesize(DigibeeTriggerKind::Scheduler, ['cron' => '0 0 * ? * *'])->callability()['callable'])
        ->toBeFalse();
});

it('keeps every assumption it made, so the report can name them', function () {
    $trigger = synthesize(DigibeeTriggerKind::Http);

    expect($trigger->assumptions)->not->toBeEmpty()
        ->and(implode(' ', $trigger->assumptions))->toContain('Timeout assumido');
});
