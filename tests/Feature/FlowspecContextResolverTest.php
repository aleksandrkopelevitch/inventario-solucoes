<?php

use App\Enums\ContextExtractionState;
use App\Enums\FlowspecAttachmentKind;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\FlowspecAttachment;
use App\Models\FlowspecChat;
use App\Models\Notebook;
use App\Models\Solution;
use App\Services\Flowspec\FlowspecContextResolver;
use App\Services\Flowspec\FlowspecPromptBuilder;
use Database\Seeders\FlowspecExampleSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('selects token-cache examples for a token/jwt/rest request', function () {
    $this->seed(FlowspecExampleSeeder::class);

    $context = (new FlowspecContextResolver)->resolve(
        FlowspecChat::factory()->create(),
        'crie um flowspec que gerencie cache de token jwt e faça POST rest'
    );

    expect($context->examples->pluck('slug')->all())
        ->toContain('get-token-svl', 'iam-svl-token-cache')
        ->and($context->examples)->toHaveCount(3);
});

it('selects the AD example for an ldap unlock request', function () {
    $this->seed(FlowspecExampleSeeder::class);

    $context = (new FlowspecContextResolver)->resolve(FlowspecChat::factory()->create(), 'desbloqueio de conta no ad via ldap');

    expect($context->examples->pluck('slug')->all())->toContain('ad-unlock-ldap');
});

it('falls back to the generic anchor when no tag matches', function () {
    $this->seed(FlowspecExampleSeeder::class);

    $context = (new FlowspecContextResolver)->resolve(FlowspecChat::factory()->create(), 'bom dia');

    expect($context->examples->pluck('slug')->all())->toBe([config('services.flowspec.fallback_example')]);
});

/*
|--------------------------------------------------------------------------
| Nothing is inferred any more
|--------------------------------------------------------------------------
|
| The resolver used to match Solution names in the request and fold up to 60k
| characters of their documentation into the prompt on its own. These two tests
| are the guard on that being gone: naming a system is now worth a SUGGESTION
| and nothing else, which is what makes the composer's context meter able to
| tell the truth about the next request before it is sent.
|
*/

it('injects no documentation for a solution merely named in the request', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    DocumentationPage::factory()->for(notebookFor($svl))->create(['title' => 'API', 'documentation' => 'como chamar o SVL']);

    $context = (new FlowspecContextResolver)->resolve(
        FlowspecChat::factory()->create(),
        'com base na documentação do svl, crie um post'
    );

    expect($context->pages)->toBeEmpty()
        ->and($context->hasDocumentation())->toBeFalse();
});

it('uses exactly the documentation attached to the conversation, in full', function () {
    $chat = FlowspecChat::factory()->create();
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $attached = DocumentationPage::factory()->for(notebookFor($svl))
        ->create(['title' => 'Contrato', 'position' => 1, 'documentation' => str_repeat('endpoint token post ', 200)]);
    DocumentationPage::factory()->for(notebookFor($svl))
        ->create(['title' => 'Não anexada', 'position' => 2, 'documentation' => 'nunca deveria entrar']);

    attachPage($chat, $attached);

    $context = (new FlowspecContextResolver)->resolve($chat, 'post de token no svl');

    // In full, with nothing trimmed: the attach endpoint already refused
    // anything that wouldn't fit, so trimming here would contradict the meter.
    expect($context->pages->pluck('title')->all())->toBe(['Contrato'])
        ->and($context->pages->first()->documentation)->toHaveLength(mb_strlen($attached->documentation));
});

it('reads an attached page live, so editing the documentation updates the conversation', function () {
    $chat = FlowspecChat::factory()->create();
    $page = DocumentationPage::factory()->for(notebookFor(Solution::factory()->create()))
        ->create(['title' => 'Contrato', 'documentation' => 'versão antiga']);

    attachPage($chat, $page);

    $page->update(['documentation' => 'versão nova, com o endpoint certo']);

    $context = (new FlowspecContextResolver)->resolve($chat, 'gere o pipeline');

    expect($context->pages->first()->documentation)->toBe('versão nova, com o endpoint certo');
});

it('attaches documentation from any caderno, including one linked to no solution', function () {
    $chat = FlowspecChat::factory()->create();
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create([
        'title'         => 'Processo transversal',
        'documentation' => 'conteudo do processo',
    ]);

    attachPage($chat, $page);

    $context = (new FlowspecContextResolver)->resolve($chat, 'bom dia');

    expect($context->pages->pluck('id')->all())->toBe([$page->id]);
});

it('ignores a stored reference to something that is not a page', function () {
    // Diagram references were a real, attachable kind until integration
    // documentation was retired. A row left over from then must not be read as
    // documentation — it has none — and must not break the turn either.
    $chat = FlowspecChat::factory()->create();
    $diagram = Diagram::factory()->create(['name' => 'IAM -> SVL']);

    FlowspecAttachment::factory()->for($chat, 'chat')->create([
        'kind'           => FlowspecAttachmentKind::Document,
        'label'          => $diagram->name,
        'reference_type' => Diagram::class,
        'reference_id'   => $diagram->id,
        'content'        => null,
    ]);

    $context = (new FlowspecContextResolver)->resolve($chat, 'bom dia');

    expect($context->pages)->toBeEmpty()
        ->and($context->hasDocumentation())->toBeFalse();
});

it('drops a reference whose documentation was emptied after it was attached', function () {
    $chat = FlowspecChat::factory()->create();
    $page = DocumentationPage::factory()->for(notebookFor(Solution::factory()->create()))
        ->create(['title' => 'Contrato', 'documentation' => 'algo']);

    attachPage($chat, $page);
    $page->update(['documentation' => '']);

    $context = (new FlowspecContextResolver)->resolve($chat, 'gere o pipeline');

    // A heading with no body under it reads as documentation that exists and
    // says nothing — worse than the page simply not being there.
    expect($context->pages)->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Material the user brought
|--------------------------------------------------------------------------
*/

it('inlines pasted text and keeps a pasted pipeline in its own section', function () {
    $chat = FlowspecChat::factory()->create();

    FlowspecAttachment::factory()->for($chat, 'chat')->create([
        'label'   => 'Contrato colado',
        'content' => 'POST /colaboradores devolve 201',
    ]);
    FlowspecAttachment::factory()->for($chat, 'chat')->flowspecReference('{"flowSpec":{"disconnected-root:x":[]}}')->create();

    $context = (new FlowspecContextResolver)->resolve($chat, 'ajuste o pipeline');

    expect($context->textDocs->pluck('label')->all())->toBe(['Contrato colado'])
        ->and($context->referenceFlowspecs->all())->toBe(['{"flowSpec":{"disconnected-root:x":[]}}']);

    $prompt = app(FlowspecPromptBuilder::class)->userPrompt($context, 'ajuste o pipeline', collect())->text;

    expect($prompt)
        ->toContain('# MATERIAL ANEXADO PELO USUÁRIO')
        ->toContain('POST /colaboradores devolve 201')
        ->toContain('# FLOWSPEC DE REFERÊNCIA')
        ->not->toContain('Array');
});

it('names both pipelines when two are pasted into one conversation', function () {
    $chat = FlowspecChat::factory()->create();

    FlowspecAttachment::factory()->for($chat, 'chat')->flowspecReference('{"flowSpec":{"a":[]}}')->create();
    FlowspecAttachment::factory()->for($chat, 'chat')->flowspecReference('{"flowSpec":{"b":[]}}')->create();

    $context = (new FlowspecContextResolver)->resolve($chat, 'junte os dois fluxos');
    $prompt = app(FlowspecPromptBuilder::class)->userPrompt($context, 'junte os dois fluxos', collect())->text;

    expect($prompt)->toContain('## Pipeline 1')->toContain('## Pipeline 2');
});

it('leaves a file it could not read out of the prompt entirely', function () {
    $chat = FlowspecChat::factory()->create();

    FlowspecAttachment::factory()->for($chat, 'chat')->create([
        'kind'             => FlowspecAttachmentKind::File,
        'label'            => 'planilha.xlsx',
        'content'          => null,
        'extraction_state' => ContextExtractionState::Failed,
        'extraction_note'  => 'Não foi possível ler o arquivo.',
        'token_estimate'   => 0,
    ]);

    $context = (new FlowspecContextResolver)->resolve($chat, 'gere o pipeline');

    expect($context->textDocs)->toBeEmpty()
        ->and($context->attachments)->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Suggestions — what replaced the automatic injection
|--------------------------------------------------------------------------
*/

it('suggests documentation for a solution named in the text', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $iam = Solution::factory()->create(['name' => 'IAM']);
    $iamDocs = Notebook::factory()->create(['name' => 'IAM']);
    $iamDocs->solutions()->attach($iam);
    $page = DocumentationPage::factory()->for($iamDocs)->create(['title' => 'Autenticação', 'documentation' => 'x']);
    // A diagram naming both solutions is deliberately NOT suggested: a drawing
    // has no text to put in the prompt.
    $diagram = Diagram::factory()->create(['name' => 'IAM -> SVL']);
    $diagram->participants()->attach([$iam->id => ['position' => 0], $svl->id => ['position' => 1]]);

    $suggestions = (new FlowspecContextResolver)->suggestFor('Preciso saber como o IAM autentica antes de continuar.');

    expect($suggestions)->toBe([
        ['type' => 'page', 'id' => $page->id, 'label' => 'IAM — Autenticação'],
    ]);
});

it('suggests nothing already attached to the conversation', function () {
    $chat = FlowspecChat::factory()->create();
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $page = DocumentationPage::factory()->for(notebookFor($svl))->create(['title' => 'Endpoints', 'documentation' => 'x']);

    attachPage($chat, $page);

    $resolver = new FlowspecContextResolver;

    expect($resolver->suggestFor('Preciso de mais detalhes do SVL.', $resolver->attachedKeys($chat)))->toBe([]);
});

it('suggests nothing for a name that is not in the catalog', function () {
    Solution::factory()->create(['name' => 'SVL']);

    expect((new FlowspecContextResolver)->suggestFor('integre com o Salesforce e o Workday'))->toBe([]);
});

it('matches a solution name regardless of accents and case', function () {
    // Matching is on the SOLUTION's name; the label is the CADERNO's, which is
    // how the picker and the attached chips name the same page.
    $solution = Solution::factory()->create(['name' => 'Gestão']);
    $notebook = Notebook::factory()->create(['name' => 'Gestão']);
    $notebook->solutions()->attach($solution);
    $page = DocumentationPage::factory()->for($notebook)->create(['title' => 'Fluxo', 'documentation' => 'x']);

    expect((new FlowspecContextResolver)->suggestFor('preciso do GESTAO aqui'))
        ->toBe([['type' => 'page', 'id' => $page->id, 'label' => 'Gestão — Fluxo']]);
});

it('suggests one page once, even when its caderno documents several of the named systems', function () {
    // A caderno describing an integration is legitimately linked to both ends
    // of it; naming both in one sentence must not offer the same page twice.
    $notebook = Notebook::factory()->create(['name' => 'Integração SAP ↔ SVL']);
    $notebook->solutions()->attach(Solution::factory()->create(['name' => 'SAP']));
    $notebook->solutions()->attach(Solution::factory()->create(['name' => 'SVL']));
    $page = DocumentationPage::factory()->for($notebook)->create(['title' => 'Contrato', 'documentation' => 'x']);

    expect((new FlowspecContextResolver)->suggestFor('como o SAP fala com o SVL?'))
        ->toBe([['type' => 'page', 'id' => $page->id, 'label' => 'Integração SAP ↔ SVL — Contrato']]);
});
