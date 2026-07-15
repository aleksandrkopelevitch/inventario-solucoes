<?php

use App\Services\Flowspec\DigibeeFlowspecNormalizer;
use App\Services\Flowspec\DigibeeFlowspecValidator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

/** Documento mínimo válido: root com um json-generator posicionado. */
function validFlowspecDocument(): array
{
    $id = (string) Str::uuid();

    return [
        'meta'     => [$id => ['position' => ['x' => 200, 'y' => 0]]],
        'flowSpec' => [
            'disconnected-root:' . Str::uuid() => [[
                'id'                => $id,
                'type'              => 'connector',
                'name'              => 'json-generator-connector',
                'stepName'          => 'Response',
                'doubleBracesAlias' => 'json-generator-1',
                'params'            => ['json' => '{"ok": {{ message.body }}}', 'failOnError' => false],
            ]],
        ],
    ];
}

function flowspecValidator(): DigibeeFlowspecValidator
{
    return app(DigibeeFlowspecValidator::class);
}

it('accepts a minimal valid document', function () {
    expect(flowspecValidator()->validate(validFlowspecDocument())->passes())->toBeTrue();
});

it('rejects a document without meta/flowSpec', function () {
    expect(flowspecValidator()->validate(['foo' => 'bar'])->passes())->toBeFalse();
});

it('rejects a step without meta position outside for-each tracks', function () {
    $document = validFlowspecDocument();
    $document['meta'] = [];

    $errors = flowspecValidator()->validate($document)->errors;

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('position');
});

it('accepts steps without meta position inside for-each tracks', function () {
    $document = validFlowspecDocument();
    $forEachId = (string) Str::uuid();
    $rootKey = array_key_first($document['flowSpec']);

    $document['flowSpec'][$rootKey][] = [
        'id'       => $forEachId,
        'type'     => 'connector',
        'name'     => 'for-each-connector',
        'stepName' => 'Loop',
        'params'   => [
            'onProcess'   => "{$forEachId}-onProcessTrack",
            'onException' => "{$forEachId}-onExceptionTrack",
            'expression'  => '$.body[*]',
        ],
    ];
    $document['meta'][$forEachId] = ['position' => ['x' => 400, 'y' => 0]];
    $document['flowSpec']["{$forEachId}-onProcessTrack"] = [[
        'id'       => (string) Str::uuid(), 'type' => 'connector', 'name' => 'log-connector',
        'stepName' => 'Log', 'params' => ['logLevel' => 'INFO', 'message' => 'ok'],
    ]];
    $document['flowSpec']["{$forEachId}-onExceptionTrack"] = [[
        'id'       => (string) Str::uuid(), 'type' => 'connector', 'name' => 'log-connector',
        'stepName' => 'Erro', 'params' => ['logLevel' => 'ERROR', 'message' => '{{ message.$ }}'],
    ]];

    expect(flowspecValidator()->validate($document)->passes())->toBeTrue();
});

it('rejects a choice pointing to a branch that does not exist', function () {
    $document = validFlowspecDocument();
    $rootKey = array_key_first($document['flowSpec']);
    $choiceId = (string) Str::uuid();

    $document['flowSpec'][$rootKey][] = [
        'id'        => $choiceId,
        'type'      => 'choice',
        'name'      => '',
        'stepName'  => 'Choice',
        'when'      => [['target' => 'sucesso', 'jsonPath' => '$.[?(@.status == 200)]']],
        'otherwise' => 'erro',
    ];
    $document['meta'][$choiceId] = ['position' => ['x' => 400, 'y' => 0]];

    $errors = flowspecValidator()->validate($document)->errors;

    expect(implode(' ', $errors))->toContain('target')->toContain('otherwise');
});

it('rejects a raw alias reference without the step. prefix', function () {
    $document = validFlowspecDocument();
    $rootKey = array_key_first($document['flowSpec']);
    $document['flowSpec'][$rootKey][0]['params']['json'] = '{"t": {{ json-generator-1.token }}}';

    $errors = flowspecValidator()->validate($document)->errors;

    expect(implode(' ', $errors))->toContain('step.json-generator-1');
});

it('rejects a step. reference to a nonexistent alias', function () {
    $document = validFlowspecDocument();
    $rootKey = array_key_first($document['flowSpec']);
    $document['flowSpec'][$rootKey][0]['params']['json'] = '{"t": {{ step.jslt-99.token }}}';

    $errors = flowspecValidator()->validate($document)->errors;

    expect(implode(' ', $errors))->toContain('jslt-99');
});

it('rejects a connector outside the catalog', function () {
    $document = validFlowspecDocument();
    $rootKey = array_key_first($document['flowSpec']);
    $document['flowSpec'][$rootKey][0]['name'] = 'connector-inventado';

    expect(flowspecValidator()->validate($document)->passes())->toBeFalse();
});

it('rejects a literal credential', function () {
    $document = validFlowspecDocument();
    $rootKey = array_key_first($document['flowSpec']);
    $document['flowSpec'][$rootKey][0]['params']['json'] = '{"x-api-key": "chave-secreta-123"}';

    $errors = flowspecValidator()->validate($document)->errors;

    expect(implode(' ', $errors))->toContain('Credencial literal');
});

it('rejects object-store upsert without unique/objectId', function () {
    $document = validFlowspecDocument();
    $rootKey = array_key_first($document['flowSpec']);
    $osId = (string) Str::uuid();

    $document['flowSpec'][$rootKey][] = [
        'id'       => $osId,
        'type'     => 'connector',
        'name'     => 'object-store-connector',
        'stepName' => 'Upsert token',
        'params'   => ['operation' => 'UPDATE', 'upsert' => true, 'objectStore' => 'token'],
    ];
    $document['meta'][$osId] = ['position' => ['x' => 400, 'y' => 0]];

    $errors = flowspecValidator()->validate($document)->errors;

    expect(implode(' ', $errors))->toContain('unique')->toContain('objectId');
});

it('rejects duplicated step ids', function () {
    $document = validFlowspecDocument();
    $rootKey = array_key_first($document['flowSpec']);
    $document['flowSpec'][$rootKey][] = $document['flowSpec'][$rootKey][0];

    $errors = flowspecValidator()->validate($document)->errors;

    expect(implode(' ', $errors))->toContain('duplicado');
});

it('normalizer fixes raw aliases, bad uuids and missing positions', function () {
    $document = validFlowspecDocument();
    $rootKey = array_key_first($document['flowSpec']);
    $document['flowSpec'][$rootKey][0]['id'] = 'id-quebrado';
    $document['flowSpec'][$rootKey][0]['params']['json'] = '{"t": {{ json-generator-1.token }}}';
    $document['meta'] = [];

    $result = (new DigibeeFlowspecNormalizer)->normalize($document);

    expect($result->fixes)->not->toBe([])
        ->and(flowspecValidator()->validate($result->document)->passes())->toBeTrue();
});

it('normalizer renames for-each track branches when regenerating the step id', function () {
    $badId = 'for-each-quebrado';

    $document = [
        'meta'     => [$badId => ['position' => ['x' => 200, 'y' => 0]]],
        'flowSpec' => [
            'disconnected-root:' . Str::uuid() => [[
                'id'       => $badId,
                'type'     => 'connector',
                'name'     => 'for-each-connector',
                'stepName' => 'Loop',
                'params'   => [
                    'onProcess'   => "{$badId}-onProcessTrack",
                    'onException' => "{$badId}-onExceptionTrack",
                    'expression'  => '$.body[*]',
                ],
            ]],
            "{$badId}-onProcessTrack"   => [],
            "{$badId}-onExceptionTrack" => [],
        ],
    ];

    $result = (new DigibeeFlowspecNormalizer)->normalize($document);
    $newId = $result->document['flowSpec'][array_key_first($result->document['flowSpec'])][0]['id'];

    expect($newId)->not->toBe($badId)
        ->and($result->document['flowSpec'])->toHaveKey("{$newId}-onProcessTrack")
        ->and($result->document['meta'])->toHaveKey($newId)
        ->and(flowspecValidator()->validate($result->document)->passes())->toBeTrue();
});
