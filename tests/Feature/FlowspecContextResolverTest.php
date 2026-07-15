<?php

use App\Models\DocumentationPage;
use App\Models\Solution;
use App\Services\Flowspec\FlowspecContextResolver;
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
    DocumentationPage::factory()->for($svl, 'container')->create(['title' => 'Histórico', 'position' => 2, 'documentation' => str_repeat('nada a ver com o pedido ', 10)]);

    $context = (new FlowspecContextResolver)->resolve('post de token no svl');

    expect($context->pages->pluck('title')->all())->toBe(['Endpoints'])
        ->and($context->omittedPages)->toBe(['Histórico']);
});
