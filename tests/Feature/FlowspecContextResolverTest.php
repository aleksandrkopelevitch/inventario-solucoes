<?php

use App\Models\DocumentationGroup;
use App\Models\DocumentationPage;
use App\Models\Integration;
use App\Models\Solution;
use App\Services\Flowspec\FlowspecContextResolver;
use App\Services\Flowspec\FlowspecPromptBuilder;
use Database\Seeders\FlowspecExampleSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('selects token-cache examples for a token/jwt/rest request', function () {
    $this->seed(FlowspecExampleSeeder::class);

    $context = (new FlowspecContextResolver)->resolve(
        'crie um flowspec que gerencie cache de token jwt e faça POST rest'
    );

    expect($context->examples->pluck('slug')->all())
        ->toContain('get-token-svl', 'iam-svl-token-cache')
        ->and($context->examples)->toHaveCount(3);
});

it('selects the AD example for an ldap unlock request', function () {
    $this->seed(FlowspecExampleSeeder::class);

    $context = (new FlowspecContextResolver)->resolve('desbloqueio de conta no ad via ldap');

    expect($context->examples->pluck('slug')->all())->toContain('ad-unlock-ldap');
});

it('falls back to the generic anchor when no tag matches', function () {
    $this->seed(FlowspecExampleSeeder::class);

    $context = (new FlowspecContextResolver)->resolve('bom dia');

    expect($context->examples->pluck('slug')->all())->toBe([config('services.flowspec.fallback_example')]);
});

it('prefers explicitly selected solutions over inference', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $iam = Solution::factory()->create(['name' => 'IAM']);
    DocumentationPage::factory()->for($svl, 'container')->create(['title' => 'Contrato', 'documentation' => 'endpoint do SVL']);
    DocumentationPage::factory()->for($iam, 'container')->create(['title' => 'Payloads', 'documentation' => 'payload do IAM']);

    $context = (new FlowspecContextResolver)->resolve('pipeline citando IAM', [$svl->id]);

    expect($context->solutions->pluck('id')->all())->toBe([$svl->id])
        ->and($context->pages->pluck('title')->all())->toBe(['Contrato']);
});

it('infers solutions whose name appears in the request', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    Solution::factory()->create(['name' => 'Protheus']);
    DocumentationPage::factory()->for($svl, 'container')->create(['title' => 'API', 'documentation' => 'como chamar o SVL']);

    $context = (new FlowspecContextResolver)->resolve('com base na documentação do svl, crie um post');

    expect($context->solutions->pluck('id')->all())->toBe([$svl->id])
        ->and($context->pages)->toHaveCount(1);
});

it('cuts documentation pages to the budget, flagging what was omitted', function () {
    config()->set('services.flowspec.doc_budget_chars', 60);

    $svl = Solution::factory()->create(['name' => 'SVL']);
    DocumentationPage::factory()->for($svl, 'container')->create(['title' => 'Endpoints', 'position' => 1, 'documentation' => str_repeat('endpoint token post ', 3)]);
    $cut = DocumentationPage::factory()->for($svl, 'container')->create(['title' => 'Histórico', 'position' => 2, 'documentation' => str_repeat('nada a ver com o pedido ', 10)]);

    $context = (new FlowspecContextResolver)->resolve('post de token no svl');

    expect($context->pages->pluck('title')->all())->toBe(['Endpoints'])
        ->and($context->omittedDocuments)->toBe([['type' => 'page', 'id' => $cut->id, 'label' => 'Histórico']])
        ->and($context->toMeta()['omitted_documents'])->toBe(['Histórico']);
});

it('renders omitted-document labels (not "Array") in the prompt', function () {
    config()->set('services.flowspec.doc_budget_chars', 60);

    $svl = Solution::factory()->create(['name' => 'SVL']);
    DocumentationPage::factory()->for($svl, 'container')->create(['title' => 'Endpoints', 'position' => 1, 'documentation' => str_repeat('endpoint token post ', 3)]);
    DocumentationPage::factory()->for($svl, 'container')->create(['title' => 'Histórico', 'position' => 2, 'documentation' => str_repeat('nada a ver com o pedido ', 10)]);

    $context = (new FlowspecContextResolver)->resolve('post de token no svl');
    $prompt = (new FlowspecPromptBuilder)->userPrompt($context, 'post de token no svl', collect());

    // `omittedDocuments` became list<array{type,id,label}>; the docs section
    // must render only the `label`, never "Array" (Array to string conversion).
    expect($prompt)
        ->toContain('Documentos omitidos por orçamento de contexto: Histórico')
        ->not->toContain('Array');
});

it('includes documentation of integrations the considered solutions participate in', function () {
    $iam = Solution::factory()->create(['name' => 'IAM']);
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $integration = Integration::factory()->create([
        'name'               => 'Access One -> SVL -> SAP | Gestão de Atendentes',
        'source_solution_id' => $iam->id,
        'target_solution_id' => $sap->id,
        'documentation'      => 'fluxo completo de gestão de atendentes',
    ]);
    $integration->participants()->attach([
        $iam->id => ['position' => 0],
        $svl->id => ['position' => 1],
        $sap->id => ['position' => 2],
    ]);

    $context = (new FlowspecContextResolver)->resolve('crie um flowspec para o IAM', [$iam->id]);

    expect($context->integrationDocs->pluck('name')->all())->toBe([$integration->name]);
});

it('does not include integration documentation when no considered solution participates in it', function () {
    $iam = Solution::factory()->create(['name' => 'IAM']);
    Solution::factory()->create(['name' => 'Protheus']);

    Integration::factory()->create(['documentation' => 'não deveria aparecer']);

    $context = (new FlowspecContextResolver)->resolve('crie um flowspec para o IAM', [$iam->id]);

    expect($context->integrationDocs)->toBeEmpty();
});

it('uses exactly the explicitly chosen documents, bypassing scoring and the budget cut', function () {
    config()->set('services.flowspec.doc_budget_chars', 10);

    $svl = Solution::factory()->create(['name' => 'SVL']);
    $group = DocumentationGroup::factory()->create();
    $page = DocumentationPage::factory()->for($group, 'container')->create([
        'title'         => 'Processo transversal',
        'documentation' => str_repeat('conteudo irrelevante para o pedido ', 20),
    ]);
    $integration = Integration::factory()->create(['documentation' => str_repeat('doc de integracao irrelevante ', 20)]);

    $context = (new FlowspecContextResolver)->resolve(
        'bom dia',
        [$svl->id],
        [
            ['type' => 'page', 'id' => $page->id],
            ['type' => 'integration', 'id' => $integration->id],
        ],
    );

    expect($context->pages->pluck('id')->all())->toBe([$page->id])
        ->and($context->integrationDocs->pluck('id')->all())->toBe([$integration->id])
        ->and($context->omittedDocuments)->toBe([]);
});

it('suggests documents for a solution mentioned by name but not yet considered', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $iam = Solution::factory()->create(['name' => 'IAM']);
    $page = DocumentationPage::factory()->for($iam, 'container')->create(['title' => 'Autenticação', 'documentation' => 'x']);
    $integration = Integration::factory()->create(['name' => 'IAM -> SVL', 'documentation' => 'y']);
    $integration->participants()->attach([$iam->id => ['position' => 0], $svl->id => ['position' => 1]]);

    $suggestions = (new FlowspecContextResolver)->suggestDocumentsFor(
        'Preciso saber como o IAM autentica antes de continuar.',
        collect([$svl]), // SVL already considered — IAM not yet
    );

    expect($suggestions)->toBe([
        ['type' => 'page', 'id' => $page->id, 'label' => 'IAM — Autenticação'],
        ['type' => 'integration', 'id' => $integration->id, 'label' => 'IAM -> SVL'],
    ]);
});

it('suggests nothing when every mentioned solution is already considered', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    DocumentationPage::factory()->for($svl, 'container')->create(['title' => 'Endpoints', 'documentation' => 'x']);

    $suggestions = (new FlowspecContextResolver)->suggestDocumentsFor('Preciso de mais detalhes do SVL.', collect([$svl]));

    expect($suggestions)->toBe([]);
});
