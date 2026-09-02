<?php

use App\Actions\Digibee\BuildConnectorCards;
use App\Actions\Digibee\SyncDigibeeDocs;
use App\Support\Digibee\ConnectorCardBuilder;
use App\Support\Digibee\ConnectorReference;
use App\Support\Digibee\DigibeeDocPage;
use App\Support\Digibee\DigibeeDocsCorpus;
use App\Support\Digibee\DigibeeDocsIndex;
use App\Support\Digibee\DigibeeMarkdownNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The index
|--------------------------------------------------------------------------
*/

it('reads pages, sections and missing descriptions out of llms.txt', function () {
    $pages = DigibeeDocsIndex::parse(<<<'TXT'
    # Digibee Documentation

    ## Home

    - [Welcome](https://docs.digibee.com/documentation/welcome.md): The front page.

    ## Connectors & Triggers

    - [REST V2](https://docs.digibee.com/documentation/connectors-and-triggers/connectors/web-protocols/rest-v2.md): Call HTTP endpoints.
    - [Connectors overview](https://docs.digibee.com/documentation/connectors-and-triggers/connectors/overview.md)

    ---

    # Agent Instructions
    This documentation is published with GitBook.

    ## Querying This Documentation
    Perform an HTTP GET request on a page URL with the `ask` query parameter.
    TXT);

    expect($pages)->toHaveCount(3)
        ->and($pages[0]->section)->toBe('Home')
        ->and($pages[1]->section)->toBe('Connectors & Triggers')
        ->and($pages[1]->slug())->toBe('rest-v2')
        ->and($pages[1]->parentKey())->toBe('documentation/connectors-and-triggers/connectors/web-protocols')
        // A page listed with no description is normal — 97 of the real 581 are.
        ->and($pages[2]->description)->toBe('');
});

it('lists a page once even when the index repeats it', function () {
    $pages = DigibeeDocsIndex::parse(<<<'TXT'
    ## Connectors & Triggers

    - [Overview](https://docs.digibee.com/documentation/a/overview.md): First listing.

    ## Developer Guide

    - [Overview](https://docs.digibee.com/documentation/a/overview.md): Listed again under a parent.
    TXT);

    expect($pages)->toHaveCount(1)->and($pages[0]->section)->toBe('Connectors & Triggers');
});

/*
|--------------------------------------------------------------------------
| The sync
|--------------------------------------------------------------------------
*/

function fakeDigibeeDocs(array $pages): void
{
    $index = "## Connectors & Triggers\n\n";

    foreach ($pages as $path => $body) {
        $index .= "- [{$path}](https://docs.digibee.com/{$path}): descrição.\n";
    }

    Http::fake([
        'docs.digibee.com/llms.txt' => Http::response($index),
        ...collect($pages)->mapWithKeys(fn (string $body, string $path) => [
            'docs.digibee.com/' . $path => Http::response($body),
        ])->all(),
    ]);
}

it('mirrors every page of the index and writes a manifest', function () {
    Storage::fake('local');
    fakeDigibeeDocs([
        'documentation/a.md' => "# A\n\nprimeiro",
        'documentation/b.md' => "# B\n\nsegundo",
    ]);

    $report = app(SyncDigibeeDocs::class)->handle();
    $corpus = app(DigibeeDocsCorpus::class);

    expect($report->fetched)->toBe(2)
        ->and($report->changed)->toBe(2)
        ->and($corpus->markdown('documentation/a'))->toContain('primeiro')
        ->and($corpus->pages())->toHaveCount(2);
});

it('reports a page as changed only when its content actually changed', function () {
    Storage::fake('local');
    fakeDigibeeDocs(['documentation/a.md' => "# A\n\nprimeiro"]);

    app(SyncDigibeeDocs::class)->handle();
    $second = app(SyncDigibeeDocs::class)->handle();

    expect($second->fetched)->toBe(1)->and($second->changed)->toBe(0);
});

it('keeps the rest of the manifest when only one section is re-synced', function () {
    Storage::fake('local');
    fakeDigibeeDocs([
        'documentation/a.md' => '# A',
        'documentation/b.md' => '# B',
    ]);
    app(SyncDigibeeDocs::class)->handle();

    // A scoped or limited run used to REPLACE the manifest with just its
    // subset, which left a 581-page corpus indexed as one page and every
    // connector card reporting its page as missing.
    app(SyncDigibeeDocs::class)->handle(limit: 1);

    expect(app(DigibeeDocsCorpus::class)->pages())->toHaveCount(2);
});

it('keeps going when one page of the corpus fails', function () {
    Storage::fake('local');
    Http::fake([
        'docs.digibee.com/llms.txt' => Http::response(
            "## S\n\n- [A](https://docs.digibee.com/documentation/a.md): x.\n"
            . "- [B](https://docs.digibee.com/documentation/b.md): y.\n"
        ),
        'docs.digibee.com/documentation/a.md' => Http::response('# A'),
        'docs.digibee.com/documentation/b.md' => Http::response('nope', 404),
    ]);

    $report = app(SyncDigibeeDocs::class)->handle();

    expect($report->fetched)->toBe(1)
        ->and($report->failures)->toBe(['documentation/b.md'])
        // The manifest lists only what arrived: an entry with no file behind it
        // would make every reader guard for a null body.
        ->and(app(DigibeeDocsCorpus::class)->pages())->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Connector cards
|--------------------------------------------------------------------------
*/

function docPage(string $title = 'REST V2'): DigibeeDocPage
{
    return new DigibeeDocPage(
        path: 'documentation/connectors-and-triggers/connectors/web-protocols/rest-v2.md',
        url: 'https://docs.digibee.com/documentation/connectors-and-triggers/connectors/web-protocols/rest-v2.md',
        title: $title,
        description: 'Chamadas HTTP.',
        section: 'Connectors & Triggers',
    );
}

it('reads an HTML parameter table without losing a row to the checkmark', function () {
    // Every ✅ in a "Supports DB" column is E2 9C 85 in UTF-8, and `\R` outside
    // UTF mode matches the byte 0x85 as a line break. Splitting the page with
    // `\R` therefore tore each table apart at its first checkmark and only the
    // fragment still holding `<table` was parsed — REST V2 came out with one
    // parameter instead of twenty, silently.
    $card = (new ConnectorCardBuilder)->build('rest-connector-v2', docPage(), <<<'MD'
    # REST V2

    Faz chamadas HTTP.

    ## **Parameters**

    ### **Connection**

    <table><thead><tr><th>Parameter</th><th>Description</th><th>Data type</th><th>Supports DB</th><th>Default</th><th>Visible when</th></tr></thead><tbody><tr><td><strong>URL</strong></td><td>Endereço chamado.</td><td>String</td><td>✅</td><td>N/A</td><td>—</td></tr><tr><td><strong>Verb</strong></td><td>Método HTTP.</td><td>String</td><td>❌</td><td><code>GET</code></td><td>—</td></tr><tr><td><strong>Body</strong></td><td>Corpo da requisição.</td><td>JSON</td><td>✅</td><td>N/A</td><td><strong>Verb</strong> is <code>POST</code></td></tr></tbody></table>
    MD);

    expect($card->parameterCount())->toBe(3);

    $prompt = $card->toPrompt();

    expect($prompt)->toContain('**URL**')
        ->and($prompt)->toContain('**Verb**')
        ->and($prompt)->toContain('**Body**')
        ->and($prompt)->toContain('aceita Double Braces')
        ->and($prompt)->toContain('só quando Verb is POST')
        // "N/A" and the em dash are how the docs spell "nothing here"; repeating
        // them costs tokens and says less than silence.
        ->and($prompt)->not->toContain('N/A')
        ->and($prompt)->not->toContain('só quando —');
});

it('reads a Markdown pipe parameter table', function () {
    $card = (new ConnectorCardBuilder)->build('for-each-connector', docPage('For Each'), <<<'MD'
    # For Each

    Itera sobre um array.

    ### Parameters

    | **Parameter** | **Description** | **Type** | **Supports DB** | **Default** |
    | ------------- | --------------- | -------- | --------------- | ----------- |
    | **Alias**     | Nome da saída.  | String   | ✅               | for-each-1  |
    | **Expression**| Caminho do array.| String  | ✅               | $           |

    ## Syntax options

    | Exemplo | Resultado |
    | ------- | --------- |
    | `$.a`   | primeiro  |
    MD);

    // Only the table under Parameters: "Syntax options" is a table of examples,
    // and reading it would invent two parameters that do not exist.
    expect($card->parameterCount())->toBe(2)
        ->and($card->toPrompt())->toContain('**Alias**')
        ->and($card->toPrompt())->toContain('padrão: for-each-1')
        ->and($card->toPrompt())->not->toContain('Resultado');
});

it('falls back to a bullet list when the page has no table at all', function () {
    $card = (new ConnectorCardBuilder)->build('jwt-connector', docPage('JWT V2'), <<<'MD'
    # JWT V2

    Gera e verifica tokens.

    * **Operation:** a operação executada.
    * **Public Key:** chave pública usada para verificar.
    * **Secret Key:** chave secreta usada para assinar.

    ## Messages flow

    * **Input:** um objeto.
    MD);

    expect($card->parameterCount())->toBe(3)
        ->and($card->toPrompt())->toContain('**Operation**')
        // Stops at "Messages flow": a runtime example is a bullet list too.
        ->and($card->toPrompt())->not->toContain('**Input**');
});

it('leaves a card without parameters rather than inventing them', function () {
    $card = (new ConnectorCardBuilder)->build('block-execution-connector', docPage('Block Execution'), <<<'MD'
    # Block Execution

    Agrupa um trecho do pipeline.

    ## Parameters

    **Block Execution** não tem configuração específica.
    MD);

    expect($card->parameterCount())->toBe(0)
        // With no parameters the card claims nothing — it prints the summary
        // and stops, rather than an empty heading that reads as "takes none".
        ->and($card->toPrompt())->toContain('Agrupa um trecho')
        ->and($card->toPrompt())->not->toContain('###');
});

it('builds cards only for the connectors the map names', function () {
    Storage::fake('local');
    // Never the committed artifact: this action WRITES, and a test that
    // rebuilds the cards from a two-page fixture would leave the repo holding
    // one card instead of thirty-four.
    config()->set('services.digibee.cards_path', sys_get_temp_dir() . '/digibee_cards_test.json');
    ConnectorReference::flush();
    fakeDigibeeDocs([
        'documentation/connectors-and-triggers/connectors/web-protocols/rest-v2.md' => <<<'MD'
        # REST V2

        Chamadas HTTP.

        ## Parameters

        | Parameter | Description | Type | Supports DB | Default |
        | --------- | ----------- | ---- | ----------- | ------- |
        | **URL**   | Endereço.   | String | ✅        | N/A     |
        MD,
    ]);
    app(SyncDigibeeDocs::class)->handle();

    $report = app(BuildConnectorCards::class)->handle();

    expect($report['built'])->toBe(1)
        // Every other mapped connector is reported as missing rather than
        // quietly skipped: a page the map names and the corpus lacks means the
        // sync stopped short or Digibee moved it.
        ->and($report['missing'])->not->toBeEmpty();
})->skip(fn () => ! is_file(database_path('data/digibee_connector_docs.json')), 'connector map not present');

/*
|--------------------------------------------------------------------------
| The caderno copy
|--------------------------------------------------------------------------
*/

it('strips what belongs to the docs site and points the reader at the source', function () {
    $markdown = (new DigibeeMarkdownNormalizer)->normalize(<<<'MD'
    > For the complete documentation index, see [llms.txt](https://docs.digibee.com/documentation/llms.txt).

    # REST V2

    Veja [Double Braces](/documentation/connectors-and-triggers/double-braces/overview.md).

    <figure><img src="/files/EFDnRB4SP0Pm1ML9xgub" alt=""><figcaption></figcaption></figure>

    Fim.

    ---

    # Agent Instructions
    This documentation is published with GitBook.
    MD, docPage());

    expect($markdown)->not->toContain('For the complete documentation index')
        ->and($markdown)->not->toContain('Agent Instructions')
        // GitBook does not serve /files/{id} at that path, so the alternative
        // to dropping the figure is a broken image on every other page.
        ->and($markdown)->not->toContain('<figure')
        ->and($markdown)->toContain('https://docs.digibee.com/documentation/connectors-and-triggers/double-braces/overview)')
        // The note links a HUMAN to the rendered page, not to the .md.
        ->and($markdown)->toContain('[REST V2](https://docs.digibee.com/documentation/connectors-and-triggers/connectors/web-protocols/rest-v2)')
        ->and($markdown)->toContain('Fim.');
});
