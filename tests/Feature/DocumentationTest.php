<?php

use App\Enums\UserRole;
use App\Models\Integration;
use App\Models\Solution;
use App\Models\User;
use App\Support\GitbookRenderer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function docsAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

/*
|--------------------------------------------------------------------------
| Solução — save / autorização / página
|--------------------------------------------------------------------------
*/

it('lets an admin save solution documentation', function () {
    $solution = Solution::factory()->create();

    $this->actingAs(docsAdmin())
        ->patchJson(route('solutions.docs.update', $solution), ['documentation' => "# Título\n\nCorpo."])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($solution->fresh()->documentation)->toBe("# Título\n\nCorpo.");
});

it('returns the solution documentation slot after saving', function () {
    $solution = Solution::factory()->create();

    $response = $this->actingAs(docsAdmin())
        ->patchJson(route('solutions.docs.update', $solution), ['documentation' => 'Oi'])
        ->assertOk();

    expect($response->json('updatableSlots.0.id'))->toBe('solution-documentation-slot');
});

it('forbids a viewer from saving solution documentation', function () {
    $solution = Solution::factory()->create();

    $this->actingAs(User::factory()->create()) // viewer
        ->patchJson(route('solutions.docs.update', $solution), ['documentation' => 'x'])
        ->assertForbidden();

    expect($solution->fresh()->documentation)->toBeNull();
});

it('shows the block editor to an admin on the solution docs page', function () {
    $solution = Solution::factory()->create(['documentation' => '# Oi']);

    $this->actingAs(docsAdmin())
        ->get(route('solutions.docs.edit', $solution))
        ->assertOk()
        ->assertSee('data-ak-docs-editor', false);
});

it('shows read-only rendered html to a viewer on the solution docs page', function () {
    $solution = Solution::factory()->create(['documentation' => '# Olá mundo']);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('solutions.docs.edit', $solution))
        ->assertOk();

    expect($response->getContent())
        ->toContain('html-content')
        ->toContain('<h1>Olá mundo')
        ->toContain('heading-permalink')
        ->not->toContain('data-ak-docs-editor');
});

/*
|--------------------------------------------------------------------------
| Integração — scopeBindings + save
|--------------------------------------------------------------------------
*/

it('lets an admin save integration documentation', function () {
    $solution = Solution::factory()->create();
    $integration = Integration::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $solution->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($integration, [[$solution, 0]]);

    $this->actingAs(docsAdmin())
        ->patchJson(route('solutions.integrations.docs.update', [$solution, $integration]), ['documentation' => 'Doc da integração'])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($integration->fresh()->documentation)->toBe('Doc da integração');
});

it('404s the integration docs page when the integration does not belong to the solution', function () {
    $solution = Solution::factory()->create();
    $other = Solution::factory()->create();
    $integration = Integration::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $other->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($integration, [[$other, 0]]);

    $this->actingAs(docsAdmin())
        ->get(route('solutions.integrations.docs.edit', [$solution, $integration]))
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Mídia — upload + serving
|--------------------------------------------------------------------------
*/

it('uploads documentation media and serves it via /files/{id}', function () {
    Storage::fake('public');
    $solution = Solution::factory()->create();

    $response = $this->actingAs(docsAdmin())
        ->post(route('solutions.docs.media', $solution), [
            'file' => UploadedFile::fake()->image('diagrama.png', 200, 120),
        ])
        ->assertOk()
        ->assertJson(['success' => 1]);

    $mediaId = $response->json('file.mediaId');
    expect($mediaId)->toBeInt()
        ->and($response->json('file.url'))->toContain('/files/' . $mediaId);

    expect($solution->fresh()->getMedia('docs'))->toHaveCount(1);

    // A rota autenticada serve o arquivo.
    $this->actingAs(docsAdmin())
        ->get(route('files.show', $mediaId))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
});

it('forbids a viewer from uploading documentation media', function () {
    Storage::fake('public');
    $solution = Solution::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('solutions.docs.media', $solution), [
            'file' => UploadedFile::fake()->image('x.png'),
        ])
        ->assertForbidden();
});

it('rejects a media upload with neither file nor url', function () {
    // Colar imagem de site externo manda `url`; upload manda `file`. Sem
    // nenhum dos dois, a regra `required_without` recíproca barra em 422.
    $solution = Solution::factory()->create();

    $this->actingAs(docsAdmin())
        ->postJson(route('solutions.docs.media', $solution), [])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);
});

it('rejects an external image url that is not http(s)', function () {
    $solution = Solution::factory()->create();

    $this->actingAs(docsAdmin())
        ->postJson(route('solutions.docs.media', $solution), [
            'url' => 'ftp://example.com/x.png',
        ])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);
});

/*
|--------------------------------------------------------------------------
| GitbookRenderer — notação estendida
|--------------------------------------------------------------------------
*/

it('renders gitbook notation to html-content markup', function () {
    $md = <<<'MD'
    # Título

    {% hint style="warning" %}
    Cuidado **aqui**.
    {% endhint %}

    | A | B |
    | - | - |
    | 1 | 2 |

    {% tabs %}
    {% tab title="Um" %}
    Conteúdo um.
    {% endtab %}
    {% tab title="Dois" %}
    Conteúdo dois.
    {% endtab %}
    {% endtabs %}

    ***
    MD;

    $html = app(GitbookRenderer::class)->render($md);

    expect($html)
        ->toContain('<h1>Título')
        ->toContain('heading-permalink')
        ->toContain('data-callout="warning"')
        ->toContain('<strong>aqui</strong>')
        ->toContain('<table>')
        ->toContain('data-ak-tabs=')
        ->toContain('<hr />');
});

it('renders an outline heroicon badge in hints, with a per-style default and an author override', function () {
    $default = app(GitbookRenderer::class)->render("{% hint style=\"info\" %}\nOi\n{% endhint %}");
    $override = app(GitbookRenderer::class)->render("{% hint style=\"info\" icon=\"fire\" %}\nOi\n{% endhint %}");
    $bogus = app(GitbookRenderer::class)->render("{% hint style=\"info\" icon=\"nao-existe\" %}\nOi\n{% endhint %}");

    // O badge do ícone é emitido como SVG inline (não mais um emoji em CSS ::before).
    expect($default)
        ->toContain('<span class="callout-icon"')
        ->toContain('<svg');

    // Um ícone escolhido pelo autor muda o SVG; um nome inválido cai no padrão do estilo.
    expect($override)->not->toBe($default);
    expect($bogus)->toBe($default);
});

it('renders url embeds as responsive iframes', function () {
    $md = "{% embed url=\"https://www.youtube.com/watch?v=abc123\" %}\n\n{% embed url=\"https://www.figma.com/file/xyz/Design\" %}";

    $html = app(GitbookRenderer::class)->render($md);

    expect($html)
        ->toContain('ak-embed--youtube')
        ->toContain('https://www.youtube.com/embed/abc123')
        ->toContain('ak-embed--figma')
        ->toContain('figma.com/embed');
});

it('falls back to a link for unsupported embed urls', function () {
    $html = app(GitbookRenderer::class)->render('{% embed url="https://example.com/thing" %}');

    expect($html)
        ->toContain('href="https://example.com/thing"')
        ->not->toContain('ak-embed');
});

it('renders nothing for empty documentation', function () {
    expect(app(GitbookRenderer::class)->render(null))->toBe('')
        ->and(app(GitbookRenderer::class)->render('   '))->toBe('');
});
