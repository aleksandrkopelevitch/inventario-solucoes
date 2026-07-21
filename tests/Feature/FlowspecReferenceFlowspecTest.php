<?php

use App\Actions\Flowspec\NormalizeReferenceFlowspec;
use App\Services\Flowspec\FlowspecContext;
use App\Services\Flowspec\FlowspecPromptBuilder;

/** Empty context — the prompt-builder tests here only exercise the reference section. */
function emptyFlowspecContext(): FlowspecContext
{
    return new FlowspecContext(collect(), collect(), collect(), [], collect(), []);
}

it('drops the top-level meta and minifies the reference flowspec', function () {
    $raw = json_encode([
        'meta'     => ['abc' => ['position' => ['x' => 200, 'y' => 0]]],
        'flowSpec' => ['root' => [['id' => 'abc', 'name' => 'log-connector']]],
    ], JSON_PRETTY_PRINT);

    $normalized = (new NormalizeReferenceFlowspec)->handle($raw);

    expect($normalized)->not->toContain("\n")
        ->and($normalized)->not->toContain('    ')
        ->and(json_decode($normalized, true))->toBe([
            'flowSpec' => ['root' => [['id' => 'abc', 'name' => 'log-connector']]],
        ]);
});

it('keeps a nested meta (only the top-level canvas map is dropped)', function () {
    $raw = '{"flowSpec":{"root":[{"params":{"meta":"keep-me"}}]}}';

    $normalized = (new NormalizeReferenceFlowspec)->handle($raw);

    expect($normalized)->toContain('keep-me');
});

it('does not escape unicode or slashes when minifying', function () {
    $raw = '{"flowSpec":{"url":"https://svl/cadastrar","nome":"Autenticação"}}';

    $normalized = (new NormalizeReferenceFlowspec)->handle($raw);

    expect($normalized)->toContain('https://svl/cadastrar')
        ->and($normalized)->toContain('Autenticação')
        ->and($normalized)->not->toContain('\\/')
        ->and($normalized)->not->toContain('\\u');
});

it('includes the reference flowspec section in the prompt when one is given', function () {
    $prompt = (new FlowspecPromptBuilder)->userPrompt(
        emptyFlowspecContext(),
        'ajusta esse pipeline',
        collect(),
        '{"flowSpec":{"root":[]}}',
    );

    expect($prompt)
        ->toContain('# FLOWSPEC DE REFERÊNCIA')
        ->toContain('{"flowSpec":{"root":[]}}')
        ->and(strpos($prompt, '# FLOWSPEC DE REFERÊNCIA'))->toBeLessThan(strpos($prompt, '# PEDIDO'));
});

it('omits the reference section when no reference flowspec is given', function () {
    $prompt = (new FlowspecPromptBuilder)->userPrompt(emptyFlowspecContext(), 'gera aí', collect());

    expect($prompt)->not->toContain('# FLOWSPEC DE REFERÊNCIA');
});
