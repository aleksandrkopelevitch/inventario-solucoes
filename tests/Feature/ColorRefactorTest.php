<?php

use App\Models\AttributeOption;
use App\Models\Integration;
use App\Models\Solution;
use App\View\Components\Solutions\DetailHeader;
use App\View\Components\Solutions\IntegrationsMap;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(LazilyRefreshDatabase::class);

it('renders the solution header badges with semantic tones instead of a single gray', function () {
    $solution = Solution::factory()->create(['category' => 'iam', 'criticality' => 'high']);
    AttributeOption::create(['group' => 'category', 'value' => 'iam', 'label' => 'IAM']);
    AttributeOption::create(['group' => 'criticality', 'value' => 'high', 'label' => 'Alta']);
    Cache::flush(); // AttributeOption::options() é cacheado por grupo

    // Componente isolado (via tag, para o ciclo de vida injetar a prop pública
    // $solution) — assim o HTML é só o header, sem o chrome do layout.
    $html = Blade::render('<x-solutions.detail-header :solution="$solution" />', ['solution' => $solution]);

    // Categoria = bloco verde sólido (âncora); criticidade alta = vermelho;
    // nenhuma pílula cinza `rounded-full bg-raised`. Guest (sem permissão de
    // editar) renderiza o <span> de sempre — os tons continuam ali; a versão
    // editável (<select>) usa as mesmas classes com prefixo `!` (ver
    // Solutions\DetailHeader).
    expect($html)->toContain('bg-accent text-white')           // categoria âncora
        ->and($html)->toContain('ring-crit-line')               // criticidade alta = vermelho
        ->and($html)->not->toContain('rounded-full bg-raised'); // sem pílula cinza
});

it('maps criticality to a semantic tone from the raw value', function () {
    $method = new ReflectionMethod(DetailHeader::class, 'criticalityTone');

    $tone = fn (?string $value) => $method->invoke(
        new DetailHeader(Solution::factory()->make(['criticality' => $value]))
    );

    expect($tone('high'))->toBe('crit')
        ->and($tone('critical'))->toBe('crit')
        ->and($tone('medium'))->toBe('amber')
        ->and($tone('low'))->toBe('green')
        ->and($tone(null))->toBe('green');
});

it('highlights the selected integration row with a lime border on a white background', function () {
    $solution = Solution::factory()->create();
    $integration = Integration::factory()->create();
    $integration->participants()->attach($solution->id, ['position' => 0]);

    $html = (new IntegrationsMap($solution))->render()->render();

    // Selecionada = borda/anel lima sobre fundo branco (sem preenchimento verde).
    expect($html)->toContain('aria-pressed:border-lime')
        ->and($html)->toContain('aria-pressed:bg-surface')
        ->and($html)->not->toContain('aria-pressed:bg-accent-soft');
});

it('renders diagram nodes in the navy/blue palette with shadow and draggable-anchor styling', function () {
    $html = Blade::render('<x-solutions.integration-viz />');

    expect($html)->toContain('--viz-node: #C9D4F7')  // nós lavanda/azulados (paleta do mapa mental de referência)
        ->and($html)->toContain('--viz-select: #4A90D9') // anel de seleção azul
        ->and($html)->toContain('.ak-viz-handle')    // handles arrastáveis das pontas
        ->and($html)->toContain('.ak-viz-anchor');    // âncoras candidatas (4 + 2 + 2)
});

it('gives the logo fallback a solid green anchor block (no gray tile)', function () {
    $html = Blade::render('<x-ui.logo name="AccessOne" />');

    expect($html)->toContain('bg-accent')
        ->and($html)->toContain('text-white')
        ->and($html)->not->toContain('bg-raised');
});
