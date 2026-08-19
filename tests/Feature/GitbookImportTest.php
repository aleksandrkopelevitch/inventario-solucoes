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
    Http::fake(['files.gitbook.com/*' => function () use (&$attempts) {
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
