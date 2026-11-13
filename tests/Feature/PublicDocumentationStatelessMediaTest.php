<?php

use App\Contracts\Documentable;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The magic link's media routes run without a session
|--------------------------------------------------------------------------
|
| A shared page asks `public.docs.file` once per image. Each request used to
| start a session, store it and set a cookie, for a token that already carries
| the whole decision. The page itself keeps its session: its secret locks post
| with the CSRF token it renders.
*/

it('serves a shared image without starting a session, while the page still has one', function () {
    Storage::fake('public');

    $notebook = Notebook::factory()->create(['public_token' => 'tok-stateless-1']);
    $page = DocumentationPage::factory()->for($notebook)->create(['slug' => 'pagina', 'title' => 'Página']);
    $media = $page->addMedia(UploadedFile::fake()->image('tela.png', 40, 40))
        ->toMediaCollection(Documentable::DOCS_COLLECTION);
    $page->update(['documentation' => '<figure><img src="/files/' . $media->id . '" alt="Tela"></figure>']);

    $file = $this->get(route('public.docs.file', ['tok-stateless-1', $media]))->assertOk();

    $cookies = collect($file->headers->getCookies())->map->getName();
    expect($cookies)->not->toContain(config('session.cookie'))
        ->and($cookies)->not->toContain('XSRF-TOKEN');

    // Still refused for a media id the caderno does not own.
    $other = DocumentationPage::factory()->create();
    $foreign = $other->addMedia(UploadedFile::fake()->image('x.png', 10, 10))->toMediaCollection(Documentable::DOCS_COLLECTION);
    $this->get(route('public.docs.file', ['tok-stateless-1', $foreign]))->assertNotFound();

    // The page keeps its session and CSRF cookie.
    $pageCookies = collect($this->get(route('public.docs.page', ['tok-stateless-1', 'pagina']))->assertOk()->headers->getCookies())->map->getName();
    expect($pageCookies)->toContain(config('session.cookie'))
        ->and($pageCookies)->toContain('XSRF-TOKEN');
});
