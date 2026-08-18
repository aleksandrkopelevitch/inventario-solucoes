<?php

use App\Actions\Cati\RenderSubmissionMarkdown;
use App\Actions\Cati\RenderTicketText;
use App\Enums\SubmissionSectionKey;
use App\Enums\SubmissionSectionState;
use App\Enums\SubmissionStatus;
use App\Models\Person;
use App\Models\Solution;
use App\Models\Submission;
use App\Models\SubmissionSource;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function catiFilled(array $confirmed = [], array $drafted = []): Submission
{
    $submission = Submission::factory()->withSections()->create(['name' => 'CATI SKBridge']);

    foreach ($confirmed as $key => $content) {
        $submission->section(SubmissionSectionKey::from($key))
            ->update(['content' => $content, 'state' => SubmissionSectionState::Confirmed]);
    }

    foreach ($drafted as $key => $content) {
        $submission->section(SubmissionSectionKey::from($key))
            ->update(['content' => $content, 'state' => SubmissionSectionState::Drafted]);
    }

    return $submission->fresh();
}

it('prints the ticket in the form\'s order, with the form\'s own headings', function () {
    $ticket = app(RenderTicketText::class)->handle(catiFilled([
        'summary'      => 'Ponto único de conexão.',
        'architecture' => 'VM dedicada na Google Cloud.',
    ]));

    expect($ticket)->toContain('### 1. Resumo da Proposta')
        ->toContain('### 2. Arquitetura de Solução')
        ->toContain('### 7. Alternativas Avaliadas')
        ->toContain('Ponto único de conexão.');

    // Document order (which puts "Cenário atual" second) must not leak into
    // the ticket, and the deck-only sections must not appear at all.
    expect(mb_strpos($ticket, '### 1.'))->toBeLessThan(mb_strpos($ticket, '### 2.'))
        ->and($ticket)->not->toContain('Cenário atual')
        ->and($ticket)->not->toContain('Modelo de Operação');
});

it('marks an unfilled ticket section instead of leaving a silent blank', function () {
    expect(app(RenderTicketText::class)->handle(catiFilled()))
        ->toContain('_[não preenchido]_');
});

it('ticks the final checklist from what the record actually holds', function () {
    $ticket = app(RenderTicketText::class)->handle(catiFilled(
        confirmed: ['summary' => 'Resumo.', 'standards' => 'Padrões.'],
        drafted: ['plan_costs' => 'Fases e custos.'],
    ));

    expect($ticket)->toContain('* [x] Resumo da proposta preenchido')
        ->toContain('* [x] Padrões adotados informados')
        // Drafted, not confirmed: nobody has signed it, so the box stays open.
        ->toContain('* [ ] Plano de implementação e custos estimados incluídos')
        ->toContain('* [ ] Benefícios e riscos detalhados');
});

it('never ticks the diagrams item, which is a claim about attachments', function () {
    // Fase 1 has no diagrams; deriving this from the architecture section would
    // put a false compliance claim in front of the committee.
    $ticket = app(RenderTicketText::class)->handle(catiFilled([
        'architecture' => 'VM dedicada, VPN site-to-site, C4 no anexo.',
    ]));

    expect($ticket)->toContain('* [ ] Diagramas de arquitetura anexados');
});

it('leaves a confirmed but emptied section unticked', function () {
    $submission = catiFilled(['summary' => 'Resumo.']);
    $submission->section(SubmissionSectionKey::Summary)->update(['content' => '']);

    expect(app(RenderTicketText::class)->handle($submission->fresh()))
        ->toContain('* [ ] Resumo da proposta preenchido');
});

it('renders the document with all eleven sections, not just the ticket\'s seven', function () {
    $markdown = app(RenderSubmissionMarkdown::class)->handle(catiFilled(['summary' => 'Resumo.']));

    foreach (SubmissionSectionKey::cases() as $key) {
        expect($markdown)->toContain('## ' . $key->label());
    }

    expect($markdown)->toStartWith('# CATI SKBridge')
        ->and($markdown)->toContain('_Não preenchido._');
});

it('marks a drafted section in the document itself', function () {
    // A generated text that reads as human-signed is the failure mode of this
    // whole module.
    $markdown = app(RenderSubmissionMarkdown::class)->handle(catiFilled(
        confirmed: ['summary' => 'Confirmado por um humano.'],
        drafted: ['objectives' => 'Proposto pela IA.'],
    ));

    expect($markdown)->toContain('## Objetivos _(rascunho da IA, não confirmado)_')
        ->and($markdown)->toContain('## Resumo da proposta' . "\n")
        ->and($markdown)->not->toContain('## Resumo da proposta _(rascunho');
});

it('carries the metadata a reviewer needs, skipping what is not set', function () {
    $solution = Solution::factory()->create(['name' => 'SkyMob']);
    $person = Person::factory()->create(['name' => 'Fabio Caldart']);

    $submission = Submission::factory()->withSections()->create([
        'name'                => 'CATI SKBridge',
        'solution_id'         => $solution->id,
        'requester_person_id' => $person->id,
        'status'              => SubmissionStatus::Submitted,
        'committee_date'      => '2026-09-01',
        'ticket_reference'    => null,
    ]);

    $markdown = app(RenderSubmissionMarkdown::class)->handle($submission);

    expect($markdown)->toContain('| **Solução** | SkyMob |')
        ->toContain('| **Solicitante** | Fabio Caldart |')
        ->toContain('| **Situação** | Submetida |')
        ->toContain('| **Comitê** | 01/09/2026 |')
        ->and($markdown)->not->toContain('**Chamado**');
});

it('lists the material consulted, and warns about a credential still in it', function () {
    $submission = catiFilled(['summary' => 'Resumo.']);

    SubmissionSource::factory()->create([
        'submission_id'      => $submission->id,
        'label'              => 'CATI_antigo.pptx',
        'sensitive_findings' => [['type' => 'Token JWT', 'sample' => 'eyJhbGciOiJI…']],
    ]);
    SubmissionSource::factory()->skipped()->create(['submission_id' => $submission->id]);

    $markdown = app(RenderSubmissionMarkdown::class)->handle($submission->fresh());

    expect($markdown)->toContain('## Material consultado')
        ->toContain('**CATI_antigo.pptx** — Texto extraído')
        ->toContain('⚠ possível credencial no conteúdo: Token JWT')
        // Skipped is not a failure — it reads as "goes as an attachment".
        ->toContain('**arquitetura.pdf** — Vai como anexo');
});

it('omits the appendix entirely when no material was attached', function () {
    expect(app(RenderSubmissionMarkdown::class)->handle(catiFilled()))
        ->not->toContain('Material consultado');
});
