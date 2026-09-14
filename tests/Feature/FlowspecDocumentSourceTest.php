<?php

use App\Models\FlowspecChat;
use App\Support\Flowspec\FlowspecDocumentSource;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function sourceDocument(string $message = 'oi'): array
{
    return ['meta' => [], 'flowSpec' => ['disconnected-root:a' => [
        ['id' => 'b', 'type' => 'connector', 'name' => 'log-connector', 'params' => ['message' => $message]],
    ]]];
}

function sourceChatWith(array ...$documents): FlowspecChat
{
    $chat = FlowspecChat::factory()->create();

    foreach ($documents as $document) {
        $chat->messages()->create(['role' => 'assistant', 'content' => 'pronto', 'flow_spec' => $document]);
    }

    return $chat;
}

function sourceFile(array $document): string
{
    $path = tempnam(sys_get_temp_dir(), 'flowspec') . '.json';
    file_put_contents($path, json_encode($document));

    return $path;
}

/*
|--------------------------------------------------------------------------
| A file, as before
|--------------------------------------------------------------------------
*/

it('reads a document from a file and says so', function () {
    $path = sourceFile(sourceDocument());

    $source = FlowspecDocumentSource::resolve($path, null, null);

    expect($source->ok())->toBeTrue()
        ->and($source->document)->toBe(sourceDocument())
        ->and($source->origin)->toContain($path);
});

it('refuses a path that is not a file', function () {
    expect(FlowspecDocumentSource::resolve('/nao/existe.json', null, null)->error)
        ->toContain('Não existe arquivo');
});

it('refuses a file that is not JSON', function () {
    $path = tempnam(sys_get_temp_dir(), 'flowspec');
    file_put_contents($path, '{ não é json');

    expect(FlowspecDocumentSource::resolve($path, null, null)->error)->toContain('não é JSON válido');
});

/*
|--------------------------------------------------------------------------
| The conversation — the id a person actually has
|--------------------------------------------------------------------------
*/

it('takes the LATEST generated document of a conversation', function () {
    // A conversation regenerates; the newest document is the one on screen and
    // the one somebody means.
    $chat = sourceChatWith(sourceDocument('primeiro'), sourceDocument('segundo'));

    $source = FlowspecDocumentSource::resolve(null, (string) $chat->id, null);

    expect($source->document)->toBe(sourceDocument('segundo'))
        ->and($source->origin)->toContain("conversa #{$chat->id}");
});

it('skips the prose turns when picking a conversation document', function () {
    $chat = sourceChatWith(sourceDocument('gerado'));
    $chat->messages()->create(['role' => 'assistant', 'content' => 'qual sistema do outro lado?']);

    expect(FlowspecDocumentSource::resolve(null, (string) $chat->id, null)->document)
        ->toBe(sourceDocument('gerado'));
});

it('says a conversation generated nothing rather than calling it missing', function () {
    // A chat that only ever answered in prose is ordinary — the generator has a
    // conversational mode — so reporting it as "not found" sends somebody
    // hunting for a typo in the id.
    $chat = FlowspecChat::factory()->create();
    $chat->messages()->create(['role' => 'assistant', 'content' => 'me conta mais']);

    expect(FlowspecDocumentSource::resolve(null, (string) $chat->id, null)->error)
        ->toContain('não tem nenhuma mensagem com flowSpec gerado');
});

/*
|--------------------------------------------------------------------------
| One exact message
|--------------------------------------------------------------------------
*/

it('takes the document of an exact message', function () {
    $chat = sourceChatWith(sourceDocument('antigo'), sourceDocument('novo'));
    $older = $chat->messages()->orderBy('id')->first();

    $source = FlowspecDocumentSource::resolve(null, null, (string) $older->id);

    expect($source->document)->toBe(sourceDocument('antigo'))
        ->and($source->origin)->toContain("mensagem #{$older->id}");
});

it('refuses a message that answered in prose, naming that', function () {
    $chat = FlowspecChat::factory()->create();
    $message = $chat->messages()->create(['role' => 'assistant', 'content' => 'qual o sistema?']);

    expect(FlowspecDocumentSource::resolve(null, null, (string) $message->id)->error)
        ->toContain('não carrega flowSpec');
});

it('refuses a message that does not exist', function () {
    expect(FlowspecDocumentSource::resolve(null, null, '999999')->error)->toContain('Nenhuma mensagem');
});

/*
|--------------------------------------------------------------------------
| Exactly one source
|--------------------------------------------------------------------------
*/

it('refuses when no source is given', function () {
    expect(FlowspecDocumentSource::resolve(null, null, null)->error)
        ->toContain('Passe uma fonte do documento');
});

it('refuses two sources instead of preferring one', function () {
    // A silent precedence is how somebody writes the document they did not
    // mean — and nothing on this platform deletes a pipeline.
    $chat = sourceChatWith(sourceDocument());

    $error = (string) FlowspecDocumentSource::resolve(sourceFile(sourceDocument()), (string) $chat->id, null)->error;

    expect($error)->toContain('apenas UMA fonte')
        ->and($error)->toContain('--file')
        ->and($error)->toContain('--chat');
});

it('treats an empty option as absent, the way a console passes one', function () {
    // `--chat=` with nothing after it arrives as '' rather than null, and
    // reading that as "a source was given" would refuse every plain --file run.
    $path = sourceFile(sourceDocument());

    expect(FlowspecDocumentSource::resolve($path, '', '')->ok())->toBeTrue();
});
