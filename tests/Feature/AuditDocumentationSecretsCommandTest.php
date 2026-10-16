<?php

use App\Models\DocumentationPage;
use App\Models\Notebook;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| documentation:secrets-audit — what the ordinal bug left behind
|--------------------------------------------------------------------------
|
| Every page below holds the real-looking value `s3nh4-real`, and every test
| asserts it never reaches the output: the report names values by ordinal.
|
*/

function auditPage(string $title, string $documentation, ?Notebook $notebook = null): DocumentationPage
{
    return DocumentationPage::factory()
        ->for($notebook ?? Notebook::factory()->create(['name' => 'Integrações']))
        ->create(['title' => $title, 'documentation' => $documentation]);
}

it('reports a value that became the text of a marker as BROKEN', function () {
    // A value deleted above it, the editor still naming the old number: the
    // save stored the marker itself.
    auditPage('Credenciais', 'a {% secret %}s3nh4-real{% endsecret %} b {% secret %}[[SECRET-3]]{% endsecret %}');

    $this->artisan('documentation:secrets-audit')
        ->expectsOutputToContain('Integrações › Credenciais')
        ->expectsOutputToContain('BROKEN value #2 holds the text of a marker instead of a value')
        ->expectsOutputToContain('Checked 1 page(s) with protected values: 1 broken, 0 suspect.')
        ->doesntExpectOutputToContain('s3nh4-real')
        ->assertSuccessful();
});

it('reports a marker left standing in the prose as BROKEN', function () {
    auditPage('Credenciais', 'a {% secret %}s3nh4-real{% endsecret %} e depois [[SECRET-7]] solto');

    $this->artisan('documentation:secrets-audit')
        ->expectsOutputToContain('BROKEN 1 marker(s) in the prose, outside any value')
        ->doesntExpectOutputToContain('s3nh4-real')
        ->assertSuccessful();
});

it('reports identical values as SUSPECT, by ordinal and never by content', function () {
    // A value added above two others, copied over them one save at a time.
    auditPage('Tokens', 'x {% secret %}s3nh4-real{% endsecret %} y {% secret %}s3nh4-real{% endsecret %} z {% secret %}outro{% endsecret %}');

    $this->artisan('documentation:secrets-audit')
        ->expectsOutputToContain('SUSPECT values #1 and #2 are identical')
        ->expectsOutputToContain('0 broken, 1 suspect.')
        ->doesntExpectOutputToContain('s3nh4-real')
        ->doesntExpectOutputToContain('outro')
        ->assertSuccessful();
});

it('lists a healthy page only when asked for all of them', function () {
    auditPage('Saudável', 'a {% secret %}s3nh4-real{% endsecret %} b {% secret %}outro{% endsecret %}');

    $this->artisan('documentation:secrets-audit')
        ->doesntExpectOutputToContain('Saudável')
        ->expectsOutputToContain('Checked 1 page(s) with protected values: 0 broken, 0 suspect.')
        ->assertSuccessful();

    $this->artisan('documentation:secrets-audit', ['--all' => true])
        ->expectsOutputToContain('Integrações › Saudável')
        ->expectsOutputToContain('no sign of damage')
        ->doesntExpectOutputToContain('s3nh4-real')
        ->assertSuccessful();
});

it('looks at one caderno when told to', function () {
    $mine = Notebook::factory()->create(['name' => 'SAP']);
    auditPage('Minha', '{% secret %}[[SECRET-1]]{% endsecret %}', $mine);
    auditPage('Outra', '{% secret %}[[SECRET-1]]{% endsecret %}');

    $this->artisan('documentation:secrets-audit', ['--notebook' => $mine->slug])
        ->expectsOutputToContain('SAP › Minha')
        ->doesntExpectOutputToContain('Outra')
        ->expectsOutputToContain('Checked 1 page(s)')
        ->assertSuccessful();
});

it('ignores pages with no protected value at all', function () {
    auditPage('Prosa', 'Um texto que fala de secret sem ter nenhum.');

    $this->artisan('documentation:secrets-audit', ['--all' => true])
        ->doesntExpectOutputToContain('Prosa')
        ->expectsOutputToContain('Checked 0 page(s)')
        ->assertSuccessful();
});
