<?php

use App\Actions\Documentation\ImportGitbookSpace;
use App\Contracts\Documentable;
use App\Exceptions\GitbookApiException;
use App\Models\DocumentationGroup;
use App\Support\Gitbook\GitbookMarkdownNormalizer;
use App\Support\Gitbook\GitbookPageTree;
use App\Support\GitbookRenderer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config()->set('services.gitbook.token', 'test-token');
    config()->set('services.gitbook.url', 'https://api.gitbook.com/v1');
    config()->set('services.gitbook.timeout', 30);
    config()->set('services.gitbook.max_asset_bytes', 1048576);
    config()->set('services.gitbook.retries', 3);
    // No real sleeping between retries in tests.
    config()->set('services.gitbook.retry_sleep', 0);
    Storage::fake('public');
});

/**
 * Fakes the four endpoints an import touches. `$markdown` is keyed by page id,
 * or a closure returning that map — `Http::fake()` MERGES stubs instead of
 * replacing them, so a test that re-imports with different content has to vary
 * it from inside the one stub, not by faking twice.
 *
 * @param  array<int, array<string, mixed>>  $tree
 * @param  array<string, string>|Closure  $markdown
 */
function fakeGitbook(array $tree, array|Closure $markdown, string $title = 'Manual de Integrações'): void
{
    $resolve = $markdown instanceof Closure ? $markdown : fn () => $markdown;

    Http::fake([
        'api.gitbook.com/v1/spaces/space-1/content/pages*' => Http::response(['pages' => $tree]),
        'api.gitbook.com/v1/spaces/space-1/content/page/*' => function (Request $request) use ($resolve) {
            $id = str($request->url())->before('?')->afterLast('/')->value();

            return Http::response(['markdown' => $resolve()[$id] ?? '']);
        },
        // Must precede the bare `spaces/space-1*` below: stubs are matched in
        // insertion order, and that one would swallow this URL too.
        'api.gitbook.com/v1/spaces/space-1/content/files*' => Http::response(['items' => [
            ['id' => 'gbFileAbc', 'name' => 'diagrama.png', 'downloadURL' => 'https://files.gitbook.com/diagrama.png'],
        ]]),
        'api.gitbook.com/v1/spaces/space-1*'    => Http::response(['id' => 'space-1', 'title' => $title]),
        'api.gitbook.com/v1/orgs/org-1/spaces*' => Http::response([
            'items' => [['id' => 'space-1', 'title' => $title, 'visibility' => 'private']],
        ]),
        'api.gitbook.com/v1/orgs*' => Http::response(['items' => [['id' => 'org-1', 'title' => 'Leo Madeiras']]]),
    ]);
}

/*
|--------------------------------------------------------------------------
| Flattening the tree — the one real structural mismatch
|--------------------------------------------------------------------------
*/

it('flattens a nested space depth-first and carries ancestry into the titles', function () {
    $tree = new GitbookPageTree([
        ['id' => 'p1', 'type' => 'document', 'title' => 'Visão geral'],
        ['id' => 'g1', 'type' => 'group', 'title' => 'Começando', 'pages' => [
            ['id' => 'p2', 'type' => 'document', 'title' => 'Instalação', 'pages' => [
                ['id' => 'p3', 'type' => 'document', 'title' => 'Requisitos'],
            ]],
        ]],
    ]);

    expect(array_map(fn ($page) => $page->title, $tree->pages()))->toBe([
        'Visão geral',
        'Começando › Instalação',
        'Começando › Instalação › Requisitos',
    ]);
    expect(array_map(fn ($page) => $page->id, $tree->pages()))->toBe(['p1', 'p2', 'p3']);
});

it('can keep nested titles bare', function () {
    $tree = new GitbookPageTree([
        ['id' => 'g1', 'type' => 'group', 'title' => 'Começando', 'pages' => [
            ['id' => 'p2', 'type' => 'document', 'title' => 'Instalação'],
        ]],
    ], prefixTitles: false);

    expect($tree->pages()[0]->title)->toBe('Instalação');
    expect($tree->pages()[0]->origin())->toBe('Começando › Instalação');
});

it('skips link and computed nodes and counts them instead of losing them quietly', function () {
    $tree = new GitbookPageTree([
        ['id' => 'p1', 'type' => 'document', 'title' => 'Real'],
        ['id' => 'l1', 'type' => 'link', 'title' => 'Site externo'],
        ['id' => 'c1', 'type' => 'computed', 'title' => 'API reference'],
    ]);

    expect($tree->pages())->toHaveCount(1);
    expect($tree->skipped())->toBe(['link' => 1, 'computed' => 1]);
});

/*
|--------------------------------------------------------------------------
| Normalizing the Markdown dialect
|--------------------------------------------------------------------------
*/

it('leaves the four supported constructs alone', function () {
    $markdown = <<<'MD'
    {% hint style="warning" %}
    Cuidado.
    {% endhint %}

    {% tabs %}
    {% tab title="REST" %}
    Corpo.
    {% endtab %}
    {% endtabs %}

    {% file src="https://files.gitbook.com/doc.pdf" %}
    MD;

    expect(app(GitbookMarkdownNormalizer::class)->normalize($markdown))->toBe(trim($markdown));
});

it('strips extra attributes that would break this app is strict tag regexes', function () {
    $normalized = app(GitbookMarkdownNormalizer::class)->normalize(
        '{% embed url="https://youtu.be/abc" fullWidth="false" %}' . "\n"
        . '{% file src="/doc.pdf" caption="Anexo" %}'
    );

    expect($normalized)->toContain('{% embed url="https://youtu.be/abc" %}');
    expect($normalized)->toContain('{% file src="/doc.pdf" %}');
    expect($normalized)->not->toContain('fullWidth');
    expect($normalized)->not->toContain('caption=');
});

it('down-converts a content-ref to the link it always was', function () {
    $normalized = app(GitbookMarkdownNormalizer::class)->normalize(<<<'MD'
    {% content-ref url="começando/instalacao.md" %}
    [instalacao.md](começando/instalacao.md)
    {% endcontent-ref %}
    MD);

    expect($normalized)->toBe('[instalacao.md](começando/instalacao.md)');
});

it('unwraps stepper and columns while keeping their content in order', function () {
    $normalized = app(GitbookMarkdownNormalizer::class)->normalize(<<<'MD'
    {% stepper %}
    {% step %}
    ### Primeiro
    Faça isso.
    {% endstep %}
    {% step %}
    ### Segundo
    Depois aquilo.
    {% endstep %}
    {% endstepper %}
    MD);

    expect($normalized)->toBe("### Primeiro\nFaça isso.\n\n### Segundo\nDepois aquilo.");
    expect($normalized)->not->toContain('{%');
});

it('keeps a code block is fence and promotes its title to a line of context', function () {
    $normalized = app(GitbookMarkdownNormalizer::class)->normalize(<<<'MD'
    {% code title="composer.json" overflow="wrap" lineNumbers="true" %}
    ```json
    {"require": {}}
    ```
    {% endcode %}
    MD);

    expect($normalized)->toBe("**composer.json**\n\n```json\n{\"require\": {}}\n```");
});

it('never leaves raw notation on the page for a construct it cannot convert', function () {
    $normalized = app(GitbookMarkdownNormalizer::class)->normalize(
        '{% include "../.gitbook/includes/aviso.md" %}' . "\n\nDepois."
    );

    expect($normalized)->toContain('{% hint style="warning" %}');
    expect($normalized)->toContain('Conteúdo reutilizável do GitBook não importado');
    expect($normalized)->toContain('aviso.md');
    expect($normalized)->toContain('Depois.');
    expect($normalized)->not->toContain('{% include');
});

it('does not touch notation or images inside a code fence', function () {
    $markdown = "```md\n{% stepper %}\n![x](https://e.com/a.png)\n```";

    expect(app(GitbookMarkdownNormalizer::class)->normalize($markdown))->toBe($markdown);
});

it('normalizes every image shape into the single-line figure the editor reads', function () {
    $normalized = app(GitbookMarkdownNormalizer::class)->normalize(<<<'MD'
    ![Diagrama](<https://files.gitbook.com/a b.png>)

    <figure>
    <img src="https://files.gitbook.com/c.png" alt="" width="563">
    <figcaption><p>Legenda</p></figcaption>
    </figure>
    MD);

    expect($normalized)->toContain('<figure><img src="https://files.gitbook.com/a b.png" alt="Diagrama"><figcaption>Diagrama</figcaption></figure>');
    expect($normalized)->toContain('<figure><img src="https://files.gitbook.com/c.png" alt=""><figcaption>Legenda</figcaption></figure>');
    expect($normalized)->not->toContain('width="563"');
    expect($normalized)->not->toContain('<p>');
});

/*
|--------------------------------------------------------------------------
| The import itself
|--------------------------------------------------------------------------
*/

it('imports a space into a standalone group, one page per GitBook page, in reading order', function () {
    fakeGitbook(
        [
            ['id' => 'p1', 'type' => 'document', 'title' => 'Visão geral'],
            ['id' => 'g1', 'type' => 'group', 'title' => 'Começando', 'pages' => [
                ['id' => 'p2', 'type' => 'document', 'title' => 'Instalação'],
            ]],
        ],
        ['p1' => '# Visão geral', 'p2' => '# Instalação'],
    );

    $report = app(ImportGitbookSpace::class)->handle('space-1');

    expect($report->created)->toBe(2);
    expect($report->updated)->toBe(0);

    $group = DocumentationGroup::sole();
    expect($group->name)->toBe('Manual de Integrações');
    expect($group->slug)->toBe('manual-de-integracoes');

    $pages = $group->pages()->get();
    expect($pages->pluck('title')->all())->toBe(['Visão geral', 'Começando › Instalação']);
    expect($pages->pluck('position')->all())->toBe([1, 2]);
    expect($pages->first()->documentation)->toBe('# Visão geral');
    expect($pages->first()->slug)->toBe('visao-geral');
});

it('re-hosts embedded images into the page docs collection and repoints the markdown', function () {
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Com imagem']],
        ['p1' => '![Diagrama](https://files.gitbook.com/diagrama.png)'],
    );
    Http::fake(['files.gitbook.com/*' => Http::response('binary-png', 200, ['Content-Type' => 'image/png'])]);

    $report = app(ImportGitbookSpace::class)->handle('space-1');
    $page = DocumentationGroup::sole()->pages()->sole();
    $media = $page->getMedia(Documentable::DOCS_COLLECTION)->sole();

    expect($report->assets)->toBe(1);
    expect($report->failures)->toBe([]);
    expect($media->file_name)->toBe('diagrama.png');
    expect($page->documentation)->toBe(
        '<figure><img src="/files/' . $media->id . '" alt="Diagrama"><figcaption>Diagrama</figcaption></figure>'
    );
    expect($page->documentation)->not->toContain('gitbook.com');
});

it('fetches an asset used twice only once', function () {
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Repetida']],
        ['p1' => "![a](https://files.gitbook.com/x.png)\n\n![a](https://files.gitbook.com/x.png)"],
    );
    Http::fake(['files.gitbook.com/*' => Http::response('png', 200, ['Content-Type' => 'image/png'])]);

    $report = app(ImportGitbookSpace::class)->handle('space-1');

    expect($report->assets)->toBe(1);
    expect(DocumentationGroup::sole()->pages()->sole()->getMedia(Documentable::DOCS_COLLECTION))->toHaveCount(1);
});

it('leaves an asset that will not download in place, and names it', function () {
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Quebrada']],
        ['p1' => '![x](https://files.gitbook.com/gone.png)'],
    );
    Http::fake(['files.gitbook.com/*' => Http::response('', 404)]);

    $report = app(ImportGitbookSpace::class)->handle('space-1');

    expect($report->assets)->toBe(0);
    expect($report->failures)->toHaveCount(1);
    expect($report->failures[0])->toContain('gone.png');
    expect(DocumentationGroup::sole()->pages()->sole()->documentation)
        ->toContain('https://files.gitbook.com/gone.png');
});

it('refuses an asset over the size ceiling instead of storing it', function () {
    config()->set('services.gitbook.max_asset_bytes', 8);
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Grande']],
        ['p1' => '![x](https://files.gitbook.com/huge.png)'],
    );
    Http::fake(['files.gitbook.com/*' => Http::response(str_repeat('a', 64), 200, ['Content-Type' => 'image/png'])]);

    $report = app(ImportGitbookSpace::class)->handle('space-1');

    expect($report->assets)->toBe(0);
    expect($report->failures[0])->toContain('limite');
});

it('is re-runnable: a second import updates the same pages instead of duplicating them', function () {
    $content = ['p1' => '# Primeira versão'];
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Visão geral']],
        function () use (&$content) {
            return $content;
        },
    );

    app(ImportGitbookSpace::class)->handle('space-1');

    $content = ['p1' => '# Segunda versão'];
    $report = app(ImportGitbookSpace::class)->handle('space-1');

    expect($report->created)->toBe(0);
    expect($report->updated)->toBe(1);
    expect(DocumentationGroup::count())->toBe(1);
    expect(DocumentationGroup::sole()->pages()->sole()->documentation)->toBe('# Segunda versão');
});

it('does not accumulate media across re-imports of the same page', function () {
    $markdown = ['p1' => '![x](https://files.gitbook.com/x.png)'];
    $tree = [['id' => 'p1', 'type' => 'document', 'title' => 'Com imagem']];

    fakeGitbook($tree, $markdown);
    Http::fake(['files.gitbook.com/*' => Http::response('png', 200, ['Content-Type' => 'image/png'])]);

    app(ImportGitbookSpace::class)->handle('space-1');
    app(ImportGitbookSpace::class)->handle('space-1');

    expect(DocumentationGroup::sole()->pages()->sole()->getMedia(Documentable::DOCS_COLLECTION))->toHaveCount(1);
});

it('keeps two identically titled GitBook pages as two pages', function () {
    fakeGitbook(
        [
            ['id' => 'g1', 'type' => 'group', 'title' => 'A', 'pages' => [['id' => 'p1', 'type' => 'document', 'title' => 'Notas']]],
            ['id' => 'g2', 'type' => 'group', 'title' => 'A', 'pages' => [['id' => 'p2', 'type' => 'document', 'title' => 'Notas']]],
        ],
        ['p1' => 'um', 'p2' => 'dois'],
    );

    $report = app(ImportGitbookSpace::class)->handle('space-1');

    expect($report->created)->toBe(2);
    expect(DocumentationGroup::sole()->pages()->get()->pluck('documentation')->all())->toBe(['um', 'dois']);
});

it('accepts an explicit group name', function () {
    fakeGitbook([['id' => 'p1', 'type' => 'document', 'title' => 'X']], ['p1' => 'y']);

    app(ImportGitbookSpace::class)->handle('space-1', groupName: 'Legado GitBook');

    expect(DocumentationGroup::sole()->name)->toBe('Legado GitBook');
});

it('writes nothing on a dry run', function () {
    fakeGitbook(
        [
            ['id' => 'p1', 'type' => 'document', 'title' => 'Visão geral'],
            ['id' => 'l1', 'type' => 'link', 'title' => 'Externo'],
        ],
        ['p1' => '# Visão geral'],
    );

    $report = app(ImportGitbookSpace::class)->handle('space-1', dryRun: true);

    expect($report->group)->toBeNull();
    expect($report->planned)->toBe(['Visão geral']);
    expect($report->skipped['link'])->toBe(1);
    expect(DocumentationGroup::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The command
|--------------------------------------------------------------------------
*/

it('lists organizations and spaces with the ids the import needs', function () {
    fakeGitbook([], []);

    $this->artisan('gitbook:import --list')
        ->expectsOutputToContain('Leo Madeiras')
        ->expectsOutputToContain('space-1')
        ->assertSuccessful();
});

it('imports every space of the only organization with --all', function () {
    fakeGitbook([['id' => 'p1', 'type' => 'document', 'title' => 'X']], ['p1' => 'y']);

    $this->artisan('gitbook:import --all')->assertSuccessful();

    expect(DocumentationGroup::sole()->pages()->sole()->documentation)->toBe('y');
});

it('fails clearly when no token is configured', function () {
    config()->set('services.gitbook.token', null);

    $this->artisan('gitbook:import --list')
        ->expectsOutputToContain('GITBOOK_API_TOKEN')
        ->assertFailed();
});

it('reports a GitBook API error as a readable line, not a stack trace', function () {
    Http::fake(['api.gitbook.com/*' => Http::response(['error' => ['message' => 'Space not found']], 404)]);

    // Asserted on the exception rather than the rendered line: the error
    // component hard-wraps at the terminal width, so any substring far enough
    // into a long message straddles a newline and can't be matched.
    $message = '';

    try {
        app(ImportGitbookSpace::class)->handle('space-1');
    } catch (GitbookApiException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('GitBook API 404')
        ->toContain('no such id')
        ->toContain('--list')
        ->toContain('Space not found');

    $this->artisan('gitbook:import --space=space-1')
        ->expectsOutputToContain('GitBook API 404')
        ->assertFailed();
});

it('refuses to point several spaces at one group name', function () {
    fakeGitbook([], []);

    $this->artisan('gitbook:import --space=a --space=b --group=Um')
        ->expectsOutputToContain('single group')
        ->assertFailed();
});

it('fails when given nothing to import', function () {
    fakeGitbook([], []);

    $this->artisan('gitbook:import')
        ->expectsOutputToContain('--list')
        ->assertFailed();
});

/*
|--------------------------------------------------------------------------
| End to end — the imported page has to RENDER, not just save
|--------------------------------------------------------------------------
*/

it('produces markdown the read-only renderer turns into real blocks, with no notation left over', function () {
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Tudo junto']],
        ['p1' => <<<'MD'
        # Integração

        {% hint style="danger" %}
        Não repita a chamada.
        {% endhint %}

        {% stepper %}
        {% step %}
        ### Autenticar
        Use o token.
        {% endstep %}
        {% endstepper %}

        {% code title="exemplo.json" lineNumbers="true" %}
        ```json
        {"ok": true}
        ```
        {% endcode %}

        {% tabs %}
        {% tab title="REST" %}
        Chame o endpoint.
        {% endtab %}
        {% endtabs %}

        {% content-ref url="outra.md" %}
        [outra.md](outra.md)
        {% endcontent-ref %}

        ![Fluxo](https://files.gitbook.com/fluxo.png)
        MD],
    );
    Http::fake(['files.gitbook.com/*' => Http::response('png', 200, ['Content-Type' => 'image/png'])]);

    app(ImportGitbookSpace::class)->handle('space-1');
    $page = DocumentationGroup::sole()->pages()->sole();
    $html = app(GitbookRenderer::class)->render($page->documentation);

    expect($html)->toContain('data-callout="danger"')      // hint survived
        ->toContain('data-ak-tabs')                        // tabs survived
        ->toContain('<h3>Autenticar')                       // stepper unwrapped, content kept
        ->toContain('exemplo.json')                        // code title kept as context
        ->toContain('<code class="language-json">')        // fence intact
        ->toContain('href="outra.md"')                     // content-ref became a link
        ->toContain('<img src="/files/');                  // image re-hosted locally
    expect($html)->not->toContain('{%');
    expect($page->documentation)->not->toContain('gitbook.com');
});

/*
|--------------------------------------------------------------------------
| Transient network failures — an import is a long chain of requests
|--------------------------------------------------------------------------
*/

it('retries a request that never landed and carries on', function () {
    $attempts = 0;
    Http::fake([
        'api.gitbook.com/v1/spaces/space-1/content/pages*' => function () use (&$attempts) {
            $attempts++;

            // The real failure this covers: WSL2's DNS resolution timing out
            // mid-import ("cURL error 28: Resolving timed out"), observed on the
            // first live run against a real space.
            if ($attempts === 1) {
                throw new ConnectionException('cURL error 28: Resolving timed out after 5000 milliseconds');
            }

            return Http::response(['pages' => [['id' => 'p1', 'type' => 'document', 'title' => 'Sobreviveu']]]);
        },
        'api.gitbook.com/v1/spaces/space-1/content/page/*' => Http::response(['markdown' => 'conteúdo']),
        'api.gitbook.com/v1/spaces/space-1*'               => Http::response(['id' => 'space-1', 'title' => 'Espaço']),
    ]);

    $report = app(ImportGitbookSpace::class)->handle('space-1');

    expect($attempts)->toBe(2);
    expect($report->created)->toBe(1);
    expect(DocumentationGroup::sole()->pages()->sole()->documentation)->toBe('conteúdo');
});

it('retries a 429 but never a 404', function () {
    $pages = 0;
    $page = 0;
    Http::fake([
        'api.gitbook.com/v1/spaces/space-1/content/pages*' => function () use (&$pages) {
            $pages++;

            return $pages === 1
                ? Http::response(['error' => ['message' => 'slow down']], 429)
                : Http::response(['pages' => [['id' => 'p1', 'type' => 'document', 'title' => 'X']]]);
        },
        'api.gitbook.com/v1/spaces/space-1/content/page/*' => function () use (&$page) {
            $page++;

            return Http::response(['error' => ['message' => 'gone']], 404);
        },
        'api.gitbook.com/v1/spaces/space-1*' => Http::response(['id' => 'space-1', 'title' => 'Espaço']),
    ]);

    expect(fn () => app(ImportGitbookSpace::class)->handle('space-1'))
        ->toThrow(GitbookApiException::class);

    expect($pages)->toBe(2);  // 429 → retried
    expect($page)->toBe(1);   // 404 is an answer, not a blip
});

it('reports a network failure that outlives the retries as a readable line', function () {
    Http::fake(['api.gitbook.com/*' => fn () => throw new ConnectionException('Resolving timed out')]);

    $this->artisan('gitbook:import --space=space-1')
        ->expectsOutputToContain('Could not reach GitBook')
        ->assertFailed();
});

it('retries a failed asset download before giving up on the image', function () {
    $attempts = 0;
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Com imagem']],
        ['p1' => '![x](https://files.gitbook.com/x.png)'],
    );
    Http::fake(['files.gitbook.com/*' => function (Request $request) use (&$attempts) {
        // The importer HEADs before it GETs (see the size-ceiling tests below);
        // only the GET attempts are the ones under test here.
        if ($request->method() === 'HEAD') {
            return Http::response('', 200);
        }

        $attempts++;

        if ($attempts === 1) {
            throw new ConnectionException('Resolving timed out');
        }

        return Http::response('png', 200, ['Content-Type' => 'image/png']);
    }]);

    $report = app(ImportGitbookSpace::class)->handle('space-1');

    expect($attempts)->toBe(2);
    expect($report->imported ?? $report->assets)->toBe(1);
    expect($report->failures)->toBe([]);
});

it('never sends the API token to the asset host', function () {
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Com imagem']],
        ['p1' => '![x](https://files.gitbook.com/x.png)'],
    );
    Http::fake(['files.gitbook.com/*' => Http::response('png', 200, ['Content-Type' => 'image/png'])]);

    app(ImportGitbookSpace::class)->handle('space-1');

    // An asset URL is a different host; the bearer belongs only to the API.
    Http::assertSent(fn (Request $request) => ! str_contains($request->url(), 'files.gitbook.com')
        || ! $request->hasHeader('Authorization'));
});

/*
|--------------------------------------------------------------------------
| Shapes found by auditing the real corpus (613 pages, 38 spaces)
|--------------------------------------------------------------------------
*/

it('drops the closer of a PAIRED file or embed, keeping its caption', function () {
    // 11 of 408 files and 7 of 23 embeds in the real corpus use GitBook's
    // paired form. This app writes both as a single tag, so re-emitting the
    // closer printed a literal `{% endfile %}` on the page.
    $normalized = app(GitbookMarkdownNormalizer::class)->normalize(<<<'MD'
    {% file src="/files/JtfK3TAl5HdGMFBnAKwq" %}
    História (temp)
    {% endfile %}

    {% embed url="https://youtu.be/abc" %}
    Legenda do vídeo
    {% endembed %}
    MD);

    expect($normalized)->toContain('{% file src="/files/JtfK3TAl5HdGMFBnAKwq" %}')
        ->toContain('História (temp)')
        ->toContain('{% embed url="https://youtu.be/abc" %}')
        ->toContain('Legenda do vídeo');
    expect($normalized)->not->toContain('endfile')
        ->not->toContain('endembed');
});

it('still keeps the closers of hint and tabs, which really are paired here', function () {
    $markdown = "{% hint style=\"info\" %}\nOi\n{% endhint %}\n\n{% tabs %}\n{% tab title=\"A\" %}\nx\n{% endtab %}\n{% endtabs %}";

    expect(app(GitbookMarkdownNormalizer::class)->normalize($markdown))->toBe($markdown);
});

it('lifts notation out of a blockquote instead of printing it as text', function () {
    // Real page: `> {% code lineNumbers="true" expandable="true" %}`. Both
    // parsers are line-anchored, so a construct inside a quote is unreachable —
    // the marker is dropped so the construct works at top level.
    $normalized = app(GitbookMarkdownNormalizer::class)->normalize(<<<'MD'
    > {% code lineNumbers="true" expandable="true" %}
    > ```sql
    > select 1
    > ```
    > {% endcode %}
    MD);

    expect($normalized)->not->toContain('{%');
    expect($normalized)->toContain('select 1');
});

it('lifts a blockquoted hint out too, so it renders as a callout', function () {
    $html = app(GitbookRenderer::class)->render(
        app(GitbookMarkdownNormalizer::class)->normalize("> {% hint style=\"warning\" %}\n> Cuidado\n> {% endhint %}")
    );

    expect($html)->toContain('data-callout="warning"');
    expect($html)->not->toContain('{%');
});

/*
|--------------------------------------------------------------------------
| GitBook's own /files/{id} references — the same path shape we serve on
|--------------------------------------------------------------------------
*/

it('resolves a GitBook /files/{id} reference into our own media', function () {
    // The bug the first real import exposed: every one of the 20 references in
    // that space was this shape, none were absolute URLs, and the import
    // reported "0 assets re-hosted" while leaving markup that 404s against our
    // own files.show with an id belonging to GitBook.
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Com anexo']],
        ['p1' => "<figure><img src=\"/files/gbFileAbc\" alt=\"\"><figcaption></figcaption></figure>\n\n{% file src=\"/files/gbFileAbc\" %}"],
    );
    Http::fake(['files.gitbook.com/*' => Http::response('png', 200, ['Content-Type' => 'image/png'])]);

    $report = app(ImportGitbookSpace::class)->handle('space-1');
    $page = DocumentationGroup::sole()->pages()->sole();
    $media = $page->getMedia(Documentable::DOCS_COLLECTION)->sole();

    expect($report->assets)->toBe(1);
    expect($media->file_name)->toBe('diagrama.png');   // the name comes from the API, not the URL
    expect($page->documentation)
        ->toContain('<img src="/files/' . $media->id . '"')
        ->toContain('{% file src="/files/' . $media->id . '" %}')
        ->not->toContain('gbFileAbc');
});

it('names a GitBook reference the space file list does not contain', function () {
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Órfão']],
        ['p1' => '<figure><img src="/files/gbMissingXyz" alt=""><figcaption></figcaption></figure>'],
    );

    $report = app(ImportGitbookSpace::class)->handle('space-1');

    expect($report->assets)->toBe(0);
    expect($report->failures)->toHaveCount(1);
    expect($report->failures[0])->toContain('gbMissingXyz')
        ->toContain('lista de arquivos');
});

it('leaves a numeric /files/{id} alone — that one is already ours', function () {
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Já nosso']],
        ['p1' => '<figure><img src="/files/4242" alt=""><figcaption></figcaption></figure>'],
    );

    $report = app(ImportGitbookSpace::class)->handle('space-1');

    expect($report->assets)->toBe(0);
    expect($report->failures)->toBe([]);
    expect(DocumentationGroup::sole()->pages()->sole()->documentation)->toContain('/files/4242');
});

/*
|--------------------------------------------------------------------------
| A third asset shape: a plain link to a file, not an image or {% file %}
|--------------------------------------------------------------------------
*/

it('resolves a document linked with a plain <a href="/files/{id}">, not just images and {% file %}', function () {
    // Found for real: a "Sprints" table linked ~75 documents this way. Nothing
    // in rehost()'s regex had a case for an anchor at all, so none of them were
    // even attempted — no warning, no failure, just a link that 404s forever.
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Com link']],
        // The visible link TEXT is left exactly as GitBook wrote it — only the
        // href is a reference this importer resolves. Asserted separately below,
        // since a plain "not->toContain('gbFileAbc')" over the whole document
        // would also trip on that untouched label.
        ['p1' => '<table><tr><td>Doc</td><td><a href="/files/gbFileAbc">Checklist.pdf</a></td></tr></table>'],
    );
    Http::fake(['files.gitbook.com/*' => Http::response('png', 200, ['Content-Type' => 'image/png'])]);

    $report = app(ImportGitbookSpace::class)->handle('space-1');
    $page = DocumentationGroup::sole()->pages()->sole();
    $mediaId = $page->getMedia(Documentable::DOCS_COLLECTION)->sole()->id;

    expect($report->assets)->toBe(1);
    expect($page->documentation)
        ->toContain('href="/files/' . $mediaId . '"')
        ->toContain('>Checklist.pdf</a>')
        ->not->toContain('gbFileAbc');
});

it('leaves an ordinary outbound <a href="https://…"> link untouched', function () {
    // The scope guard: an anchor is only treated as an asset reference when its
    // href is /files/… — an arbitrary external link (a Jira ticket, a Drive
    // folder) is not an embedded asset, and "re-hosting" it would be wrong.
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Com link externo']],
        ['p1' => '<a href="https://drive.google.com/folder/xyz">pasta</a>'],
    );

    $report = app(ImportGitbookSpace::class)->handle('space-1');

    expect($report->assets)->toBe(0);
    expect(DocumentationGroup::sole()->pages()->sole()->documentation)->toContain('drive.google.com');
});

/*
|--------------------------------------------------------------------------
| The size ceiling must match what Spatie will actually accept
|--------------------------------------------------------------------------
*/

it('takes the smaller of its own ceiling and media-library.max_file_size', function () {
    // Real config drift found live: GITBOOK_MAX_ASSET_BYTES defaults to 20MB,
    // but this app has never published media-library.php, so Spatie's own
    // 10MB package default silently wins — an asset between the two used to
    // download in full and only THEN get rejected by addMedia().
    config()->set('services.gitbook.max_asset_bytes', 20 * 1024 * 1024);
    config()->set('media-library.max_file_size', 5 * 1024 * 1024);

    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Grande']],
        ['p1' => '![x](https://files.gitbook.com/huge.png)'],
    );
    Http::fake(['files.gitbook.com/*' => Http::response(
        str_repeat('a', 6 * 1024 * 1024), 200,
        ['Content-Type' => 'image/png', 'Content-Length' => (string) (6 * 1024 * 1024)],
    )]);

    $report = app(ImportGitbookSpace::class)->handle('space-1');

    expect($report->assets)->toBe(0);
    expect($report->failures[0])->toContain('limite');
});

it('rejects an oversized asset from its HEAD alone, without downloading the body', function () {
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Grande']],
        ['p1' => '![x](https://files.gitbook.com/huge.png)'],
    );
    Http::fake(['files.gitbook.com/*' => Http::response('', 200, [
        'Content-Type' => 'image/png', 'Content-Length' => (string) (30 * 1024 * 1024),
    ])]);

    $report = app(ImportGitbookSpace::class)->handle('space-1');

    expect($report->assets)->toBe(0);
    expect($report->failures[0])->toContain('anunciado');
    // The GET body was empty in the fake — had the code gotten that far and
    // relied only on the downloaded size, this would report 0 bytes, not the
    // Content-Length. Confirms the HEAD check fired first.
    Http::assertSent(fn (Request $r) => $r->method() === 'HEAD');
});

it('also updates a link text that mirrors the raw GitBook path, not just its href', function () {
    // GitBook falls back to showing the raw path as the link's own visible
    // text when no display name is set — found for real in a "Sprints" table
    // where every row read `<a href="/files/{id}">/files/{id}</a>`. The href
    // alone already worked; this is about not showing the reader a foreign id.
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Com link']],
        ['p1' => '<a href="/files/gbFileAbc">/files/gbFileAbc</a>'],
    );
    Http::fake(['files.gitbook.com/*' => Http::response('png', 200, ['Content-Type' => 'image/png'])]);

    $report = app(ImportGitbookSpace::class)->handle('space-1');
    $mediaId = DocumentationGroup::sole()->pages()->sole()->getMedia(Documentable::DOCS_COLLECTION)->sole()->id;

    expect(DocumentationGroup::sole()->pages()->sole()->documentation)
        ->toBe('<a href="/files/' . $mediaId . '">/files/' . $mediaId . '</a>');
});

it('leaves a real display name alone even though the href next to it changes', function () {
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Com link']],
        ['p1' => '<a href="/files/gbFileAbc">Checklist Leo Tech.pdf</a>'],
    );
    Http::fake(['files.gitbook.com/*' => Http::response('png', 200, ['Content-Type' => 'image/png'])]);

    app(ImportGitbookSpace::class)->handle('space-1');

    expect(DocumentationGroup::sole()->pages()->sole()->documentation)->toContain('>Checklist Leo Tech.pdf</a>');
});

/*
|--------------------------------------------------------------------------
| Shapes found by auditing the FULL 613-page corpus (34 more spaces)
|--------------------------------------------------------------------------
*/

it('normalizes a Markdown image even with a trailing HTML space entity after it', function () {
    // Found for real, once in 613 pages: GitBook's own export left a stray
    // `&#x20;` right after an otherwise-standalone inline image. The anchored
    // `^...$` match in image() rejected the whole line over that one trailing
    // entity, so it passed through untouched by BOTH the normalizer and the
    // asset importer (which only ever sees <figure>, never Markdown image
    // syntax) — a silently broken image, never even attempted.
    $normalized = app(GitbookMarkdownNormalizer::class)->normalize('![](/files/hL4VXO4JeXot2YpfF5Im)&#x20;');

    expect($normalized)->toBe(
        '<figure><img src="/files/hL4VXO4JeXot2YpfF5Im" alt=""><figcaption></figcaption></figure>'
    );
});

it('still leaves a real inline image mid-sentence alone despite the entity tolerance', function () {
    $line = 'Veja o diagrama ![x](https://e.com/a.png) para mais detalhes.';

    expect(app(GitbookMarkdownNormalizer::class)->normalize($line))->toBe($line);
});

it('surfaces GitBook own rejection reason instead of a bare status code', function () {
    // GitBook refuses some attachment types outright (.html, for security) and
    // says so in a short response body — worth showing instead of making an
    // operator go curl the URL by hand to find out why a download will never
    // succeed.
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Com anexo html']],
        ['p1' => '{% file src="/files/gbFileAbc" %}'],
    );
    Http::fake(['files.gitbook.com/*' => Http::response(
        "File type not supported. To protect you against potential viruses and harmful software, GitBook doesn't allow you to attach certain types of files.",
        403,
    )]);

    $report = app(ImportGitbookSpace::class)->handle('space-1');

    expect($report->failures[0])->toContain('HTTP 403')
        ->toContain('File type not supported');
});

/*
|--------------------------------------------------------------------------
| A fourth reference shape: /spaces/{otherSpaceId}/files/{id} — cross-space
|--------------------------------------------------------------------------
*/

it('resolves an asset that lives in a DIFFERENT space, by fetching that space own file list', function () {
    // Found for real: a page in one imported space referenced an asset owned
    // by a different, already-imported space via the fully-qualified
    // /spaces/{id}/files/{id} form (GitBook's short /files/{id} only works
    // within the space that owns the file).
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Referencia cruzada']],
        ['p1' => '{% file src="/spaces/foreignSpace1/files/gbForeignFile" %}'],
    );
    Http::fake([
        'api.gitbook.com/v1/spaces/foreignSpace1/content/files*' => Http::response(['items' => [
            ['id' => 'gbForeignFile', 'name' => 'planilha.xlsx', 'downloadURL' => 'https://files.gitbook.com/planilha.xlsx'],
        ]]),
        'files.gitbook.com/*' => Http::response('xlsx-bytes', 200, ['Content-Type' => 'application/octet-stream']),
    ]);

    $report = app(ImportGitbookSpace::class)->handle('space-1');
    $page = DocumentationGroup::sole()->pages()->sole();
    $media = $page->getMedia(Documentable::DOCS_COLLECTION)->sole();

    expect($report->assets)->toBe(1);
    expect($media->file_name)->toBe('planilha.xlsx');
    expect($page->documentation)->toBe('{% file src="/files/' . $media->id . '" %}');
});

it('fetches a foreign space file list only once even when referenced from two pages', function () {
    $requests = 0;
    fakeGitbook(
        [
            ['id' => 'p1', 'type' => 'document', 'title' => 'Página A'],
            ['id' => 'p2', 'type' => 'document', 'title' => 'Página B'],
        ],
        [
            'p1' => '{% file src="/spaces/foreignSpace1/files/gbForeignFile" %}',
            'p2' => '{% file src="/spaces/foreignSpace1/files/gbForeignFile" %}',
        ],
    );
    Http::fake([
        'api.gitbook.com/v1/spaces/foreignSpace1/content/files*' => function () use (&$requests) {
            $requests++;

            return Http::response(['items' => [
                ['id' => 'gbForeignFile', 'name' => 'x.png', 'downloadURL' => 'https://files.gitbook.com/x.png'],
            ]]);
        },
        'files.gitbook.com/*' => Http::response('png', 200, ['Content-Type' => 'image/png']),
    ]);

    app(ImportGitbookSpace::class)->handle('space-1');

    expect($requests)->toBe(1);
});

it('reports a cross-space reference the foreign space does not have, without aborting the import', function () {
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Referencia quebrada']],
        ['p1' => '{% file src="/spaces/foreignSpace1/files/gbMissing" %}'],
    );
    Http::fake(['api.gitbook.com/v1/spaces/foreignSpace1/content/files*' => Http::response(['items' => []])]);

    $report = app(ImportGitbookSpace::class)->handle('space-1');

    expect($report->assets)->toBe(0);
    expect($report->failures[0])->toContain('gbMissing')->toContain('não está na lista');
});

it('does not abort the whole import when the foreign space itself is inaccessible', function () {
    fakeGitbook(
        [['id' => 'p1', 'type' => 'document', 'title' => 'Espaço inacessível']],
        ['p1' => '{% file src="/spaces/foreignSpace1/files/gbX" %}'],
    );
    Http::fake(['api.gitbook.com/v1/spaces/foreignSpace1/content/files*' => Http::response(['error' => ['message' => 'forbidden']], 403)]);

    $report = app(ImportGitbookSpace::class)->handle('space-1');

    expect($report->created)->toBe(1);
    expect($report->assets)->toBe(0);
    expect($report->failures)->toHaveCount(1);
});
