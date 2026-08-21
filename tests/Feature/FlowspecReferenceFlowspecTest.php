<?php

use App\Actions\Flowspec\AttachFlowspecText;
use App\Actions\Flowspec\NormalizeReferenceFlowspec;
use App\Models\FlowspecChat;
use App\Services\Flowspec\FlowspecContextResolver;
use App\Services\Flowspec\FlowspecPromptBuilder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| A pasted pipeline is a paste, not a third kind of attachment
|--------------------------------------------------------------------------
|
| The composer used to carry a standalone "flowSpec de referência" editor, a
| third slot next to the two document pickers. It is gone: pasting a pipeline
| into the message box is recognized for what it is and still gets minified and
| still gets its own prompt section — the user just performs one gesture instead
| of choosing which of three slots the paste belonged in.
|
*/

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

    expect((new NormalizeReferenceFlowspec)->handle($raw))->toContain('keep-me');
});

it('does not escape unicode or slashes when minifying', function () {
    $raw = '{"flowSpec":{"url":"https://svl/cadastrar","nome":"Autenticação"}}';

    $normalized = (new NormalizeReferenceFlowspec)->handle($raw);

    expect($normalized)->toContain('https://svl/cadastrar')
        ->and($normalized)->toContain('Autenticação')
        ->and($normalized)->not->toContain('\\/')
        ->and($normalized)->not->toContain('\\u');
});

it('recognizes a pasted pipeline, labels it and minifies it', function () {
    $chat = FlowspecChat::factory()->create();
    $raw = json_encode([
        'meta'     => ['abc' => ['position' => ['x' => 200, 'y' => 0]]],
        'flowSpec' => ['disconnected-root:abc' => [['id' => 'abc', 'name' => 'log-connector']]],
    ], JSON_PRETTY_PRINT);

    $attachment = app(AttachFlowspecText::class)->handle($chat, $raw);

    expect($attachment->is_flowspec_reference)->toBeTrue()
        ->and($attachment->label)->toBe('flowSpec de referência')
        ->and($attachment->content)->not->toContain("\n")
        ->and($attachment->content)->not->toContain('"meta"');
});

it('treats other pasted JSON as ordinary material, not as a pipeline', function () {
    $chat = FlowspecChat::factory()->create();

    $attachment = app(AttachFlowspecText::class)->handle($chat, '{"nome":"payload de exemplo","id":7}');

    expect($attachment->is_flowspec_reference)->toBeFalse()
        // Not minified, not stripped: it's the user's material, kept verbatim.
        ->and($attachment->content)->toBe('{"nome":"payload de exemplo","id":7}');
});

it('places the reference section before the request in the prompt', function () {
    $chat = FlowspecChat::factory()->create();
    app(AttachFlowspecText::class)->handle($chat, '{"flowSpec":{"root":[]}}');

    $context = app(FlowspecContextResolver::class)->resolve($chat, 'ajusta esse pipeline');
    $prompt = app(FlowspecPromptBuilder::class)->userPrompt($context, 'ajusta esse pipeline', collect())->text;

    expect($prompt)
        ->toContain('# FLOWSPEC DE REFERÊNCIA')
        ->toContain('{"flowSpec":{"root":[]}}')
        ->and(strpos($prompt, '# FLOWSPEC DE REFERÊNCIA'))->toBeLessThan(strpos($prompt, '# PEDIDO'));
});

it('omits the reference section when nothing was pasted', function () {
    $prompt = app(FlowspecPromptBuilder::class)->userPrompt(emptyFlowspecContext(), 'gera aí', collect())->text;

    expect($prompt)->not->toContain('# FLOWSPEC DE REFERÊNCIA');
});

it('does not scan a pasted pipeline for credentials', function () {
    $chat = FlowspecChat::factory()->create();

    // Every `{{ account.* }}` reference in a real pipeline would read as a
    // finding, and the structured document is already checked downstream by
    // CredentialScrubber — flagging it here would be noise on every paste.
    $attachment = app(AttachFlowspecText::class)->handle(
        $chat,
        '{"flowSpec":{"root":[{"params":{"token":"{{ account.svl_token }}"}}]}}'
    );

    expect($attachment->sensitive_findings)->toBeNull();
});

it('flags a credential pasted as plain text, without removing it', function () {
    $chat = FlowspecChat::factory()->create();

    $attachment = app(AttachFlowspecText::class)->handle(
        $chat,
        "Autenticação do SVL\nAuthorization: Bearer abcdefghijklmnopqrstuvwxyz0123456789"
    );

    expect($attachment->hasSensitiveFindings())->toBeTrue()
        ->and($attachment->content)->toContain('Bearer abcdefghijklmnopqrstuvwxyz0123456789');
});

it('labels a plain paste after its first line', function () {
    $chat = FlowspecChat::factory()->create();

    $attachment = app(AttachFlowspecText::class)->handle(
        $chat,
        "Contrato da API de colaboradores\nPOST /colaboradores\nGET /colaboradores/{id}"
    );

    expect($attachment->label)->toBe('Contrato da API de colaboradores');
});
