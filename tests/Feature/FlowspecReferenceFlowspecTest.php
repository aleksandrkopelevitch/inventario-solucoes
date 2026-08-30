<?php

use App\Actions\Flowspec\AttachFlowspecText;
use App\Actions\Flowspec\NormalizeReferenceFlowspec;
use App\Models\DocumentationPage;
use App\Models\FlowspecAttachment;
use App\Models\FlowspecChat;
use App\Models\User;
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

/*
|--------------------------------------------------------------------------
| Telling one pasted pipeline from another
|--------------------------------------------------------------------------
|
| A `{meta, flowSpec}` document carries no name of its own — `meta` is the
| canvas position map and NormalizeReferenceFlowspec strips it — so every paste
| defaulted to the same constant. With the label now heading that attachment's
| prompt section, three identical pills also meant three identical headings, and
| a request naming one of them could not be resolved by anybody, model included.
|
*/

it('numbers a repeated default name so a conversation never shows two identical pills', function () {
    $chat = FlowspecChat::factory()->create();
    $attach = app(AttachFlowspecText::class);

    $first = $attach->handle($chat, '{"flowSpec":{"a":[]}}');
    $second = $attach->handle($chat, '{"flowSpec":{"b":[]}}');
    $third = $attach->handle($chat, '{"flowSpec":{"c":[]}}');

    // The first keeps the bare name: nothing is numbered until there is
    // something to distinguish it from.
    expect($first->label)->toBe('flowSpec de referência')
        ->and($second->label)->toBe('flowSpec de referência 2')
        ->and($third->label)->toBe('flowSpec de referência 3');
});

it('numbers a repeated paste name too, not only pipelines', function () {
    $chat = FlowspecChat::factory()->create();
    $attach = app(AttachFlowspecText::class);

    $first = $attach->handle($chat, "Contrato de integração\nlinha 2");
    $second = $attach->handle($chat, "Contrato de integração\noutra linha");

    expect($first->label)->toBe('Contrato de integração')
        ->and($second->label)->toBe('Contrato de integração 2');
});

it('counts names per conversation, not globally', function () {
    $attach = app(AttachFlowspecText::class);

    $mine = $attach->handle(FlowspecChat::factory()->create(), '{"flowSpec":{"a":[]}}');
    $theirs = $attach->handle(FlowspecChat::factory()->create(), '{"flowSpec":{"b":[]}}');

    expect($mine->label)->toBe('flowSpec de referência')
        ->and($theirs->label)->toBe('flowSpec de referência');
});

it('renames an attachment, and the new name is what the next prompt heads it with', function () {
    $user = User::factory()->create();
    $chat = FlowspecChat::factory()->for($user)->create();
    $attachment = FlowspecAttachment::factory()->for($chat, 'chat')
        ->flowspecReference('{"flowSpec":{"a":[]}}')->create();

    $this->actingAs($user)
        ->patchJson(route('flowspec.attachments.update', [$chat, $attachment]), ['label' => '  Pedidos B2B  '])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    // Trimmed on the way in — a leading space would sort and read as a
    // different name than the one that was typed.
    expect($attachment->fresh()->label)->toBe('Pedidos B2B');

    $context = (new FlowspecContextResolver)->resolve($chat->fresh(), 'estenda o Pedidos B2B');
    $prompt = app(FlowspecPromptBuilder::class)->userPrompt($context, 'estenda o Pedidos B2B', collect())->text;

    expect($prompt)->toContain('## Pedidos B2B');
});

it('refuses a blank name', function () {
    $user = User::factory()->create();
    $chat = FlowspecChat::factory()->for($user)->create();
    $attachment = FlowspecAttachment::factory()->for($chat, 'chat')->create(['label' => 'Contrato']);

    $response = $this->actingAs($user)
        ->patchJson(route('flowspec.attachments.update', [$chat, $attachment]), ['label' => ''])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('nome');
    expect($attachment->fresh()->label)->toBe('Contrato');
});

// The route sits inside the same scopeBindings() group as the DELETE, for the
// same reason: the policy is checked against {chat}, so an unscoped binding
// would let someone rename context belonging to a conversation they can see
// nothing else of.
it('refuses to rename an attachment belonging to another conversation', function () {
    $user = User::factory()->create();
    $mine = FlowspecChat::factory()->for($user)->create();
    $theirs = FlowspecChat::factory()->create();
    $attachment = FlowspecAttachment::factory()->for($theirs, 'chat')->create(['label' => 'Contrato alheio']);

    $this->actingAs($user)
        ->patchJson(route('flowspec.attachments.update', [$mine, $attachment]), ['label' => 'Meu'])
        ->assertStatus(404);

    expect($attachment->fresh()->label)->toBe('Contrato alheio');
});

// A component tag that fails to compile renders as literal text with no error
// anywhere (see the ComponentTagCompiler notes in AGENTS.md), so the one thing
// worth asserting about the pill is that the hook really reaches the browser.
it('renders the rename editor on a pill, but not on a documentation reference', function () {
    $user = User::factory()->create();
    $chat = FlowspecChat::factory()->for($user)->create();

    // Counting editors rather than asserting the absence of a URL: `route()`
    // builds the SAME string for this PATCH and for the DELETE the pill already
    // carries, so an assertDontSee on it could never fail whatever the view did.
    //
    // Three measurements, not two. Measuring a baseline that already INCLUDES
    // the document attachment and then asserting "+1 for the paste" holds just
    // as well when the document wrongly gets an editor too — the error lands in
    // the baseline and cancels itself out. Verified by removing the guard: that
    // version of this test still passed.
    $editors = fn () => substr_count(
        $this->actingAs($user)->get(route('flowspec.show', $chat))->assertOk()->getContent(),
        'data-ak-inline-edit=',
    );

    $withNothing = $editors();

    // A `document` attachment is named BY the page it points at, read live on
    // every turn — a name overwritten here would only disagree with it.
    $page = DocumentationPage::factory()->create(['documentation' => 'conteúdo']);
    FlowspecAttachment::factory()->for($chat, 'chat')->document($page)->create();

    expect($editors())->toBe($withNothing);

    $paste = FlowspecAttachment::factory()->for($chat, 'chat')->create(['label' => 'Contrato colado']);

    expect($editors())->toBe($withNothing + 1);

    $this->actingAs($user)->get(route('flowspec.show', $chat))
        ->assertSee(route('flowspec.attachments.update', [$chat, $paste]), escape: false);
});
