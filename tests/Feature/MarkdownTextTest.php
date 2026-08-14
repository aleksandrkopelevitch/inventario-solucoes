<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Person;
use App\Models\Solution;
use App\Models\User;
use App\Support\MarkdownText;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('renders the marks a note actually uses', function () {
    $html = MarkdownText::toHtml("Use o **SVL** para *pedidos*.\n\n- primeiro\n- segundo\n\n[Portal](https://leomadeiras.com.br)");

    expect($html)
        ->toContain('<strong>SVL</strong>')
        ->toContain('<em>pedidos</em>')
        ->toContain('<li>primeiro</li>')
        ->toContain('<a href="https://leomadeiras.com.br">Portal</a>');
});

it('keeps every line break the author typed', function () {
    // These fields were plain textareas rendered with `whitespace-pre-line`.
    // Markdown swallows a single newline by default, which would reflow every
    // note already in the database into one paragraph.
    expect(MarkdownText::toHtml("Primeira linha\nSegunda linha"))
        ->toContain('<br />');
});

it('strips HTML instead of trusting whoever typed the note', function () {
    $html = MarkdownText::toHtml('Antes <script>alert(1)</script><img src=x onerror=alert(1)> depois');

    expect($html)
        ->not->toContain('<script')
        ->not->toContain('onerror')
        ->toContain('Antes')
        ->toContain('depois');
});

it('refuses an unsafe link scheme', function () {
    expect(MarkdownText::toHtml('[clique](javascript:alert(1))'))
        ->not->toContain('javascript:');
});

it('renders nothing at all for a blank field, so the placeholder still shows', function () {
    expect(MarkdownText::toHtml(null))->toBe('')
        ->and(MarkdownText::toHtml('   '))->toBe('');
});

it('reads a person note, a company note, a description and a support note as formatted text', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $company = Company::factory()->create(['notes' => 'Contato via **portal**.']);
    $person = Person::factory()->create(['company_id' => $company->id, 'notes' => 'Prefere **e-mail**.']);
    $solution = Solution::factory()->create([
        'description'            => 'Sistema de **vendas**.',
        'support_operation_note' => 'Não reiniciar em **horário comercial**.',
    ]);

    $this->actingAs($admin)->get(route('people.show', $person))
        ->assertOk()
        ->assertSee('Prefere <strong>e-mail</strong>.', false);

    $this->actingAs($admin)->get(route('companies.show', $company))
        ->assertOk()
        ->assertSee('Contato via <strong>portal</strong>.', false);

    $response = $this->actingAs($admin)->get(route('solutions.show', $solution))->assertOk();
    $response->assertSee('Sistema de <strong>vendas</strong>.', false);
    $response->assertSee('Não reiniciar em <strong>horário comercial</strong>.', false);
});

it('leaves the raw Markdown in the editor, which is still a plain textarea', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $person = Person::factory()->create(['notes' => 'Prefere **e-mail**.']);

    $this->actingAs($admin)->get(route('people.show', $person))
        ->assertOk()
        // The textarea is seeded with what the author typed, not with the HTML
        // it reads back as — otherwise every save would re-encode the field.
        ->assertSee('Prefere **e-mail**.', false)
        ->assertSee('Aceita Markdown');
});
