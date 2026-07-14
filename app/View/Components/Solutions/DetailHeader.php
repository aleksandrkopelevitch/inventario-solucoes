<?php

namespace App\View\Components\Solutions;

use App\Models\AttributeOption;
use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Cabeçalho do detalhe da solução (briefing 9.2 itens 1 e 2: header +
 * bloco "Operação"). Extraído como componente próprio para que editar a
 * solução a partir da sua própria página de detalhe (via side panel)
 * também consiga atualizar o que está na tela — e não só a listagem.
 *
 * Renderável como slot atualizável: `DetailHeader::slot($solution)`.
 */
class DetailHeader extends Component
{
    use Renderable;

    public const DOM_ID = 'solution-detail-header-slot';

    public function __construct(public Solution $solution) {}

    public static function slot(Solution $solution): array
    {
        return (new static($solution))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $this->solution->loadMissing([
            'vendor:id,name,slug,logo_path,website',
            'people' => fn ($q) => $q->with('company:id,name,slug'),
        ]);

        $s = $this->solution;

        return view('components.solutions.detail-header', [
            'domId'          => self::DOM_ID,
            'techOwners'     => $s->people->where('pivot.role', 'technical'),
            'businessOwners' => $s->people->where('pivot.role', 'business'),
            'vendorContacts' => $s->people->where('pivot.role', 'vendor_contact'),
            // Cada atributo é exibido com o RÓTULO da sua dimensão (Categoria,
            // Status, …) — o valor sozinho ("Alta", "Planejado") não deixava
            // claro o que representava. Tom semântico via "Blocos Leo".
            // Sempre os 8 — mesmo sem valor: um atributo em branco vira
            // "Não informado" no card (ver detail-header.blade.php), nunca
            // some da grade (a lacuna cinza confundia com um erro de layout).
            // `value` é o valor CRU (não o label) — o select inline precisa
            // dele pra marcar a opção selecionada; `nullable` decide se o
            // card aceita limpar o campo de volta pra "Não informado".
            'facts' => collect([
                ['group' => 'category',        'label' => 'Categoria',   'value' => $s->category,        'displayLabel' => $s->category_label,        'tone' => 'anchor',                 'nullable' => false],
                ['group' => 'status',          'label' => 'Status',      'value' => $s->status,          'displayLabel' => $s->status_label,          'tone' => 'green',                  'nullable' => false],
                ['group' => 'criticality',     'label' => 'Criticidade', 'value' => $s->criticality,     'displayLabel' => $s->criticality_label,     'tone' => $this->criticalityTone(), 'nullable' => true],
                ['group' => 'environment',     'label' => 'Ambiente',    'value' => $s->environment,     'displayLabel' => $s->environment_label,     'tone' => 'green',                  'nullable' => true],
                ['group' => 'cloud',           'label' => 'Hospedagem',  'value' => $s->cloud,           'displayLabel' => $s->cloud_label,           'tone' => 'lime',                   'nullable' => true],
                ['group' => 'contract_status', 'label' => 'Contrato',    'value' => $s->contract_status, 'displayLabel' => $s->contract_status_label, 'tone' => 'amber',                  'nullable' => false],
                ['group' => 'support_type',    'label' => 'Suporte',     'value' => $s->support_type,    'displayLabel' => $s->support_type_label,    'tone' => 'neutral',                'nullable' => false],
                ['group' => 'directorate',     'label' => 'Diretoria',   'value' => $s->directorate,     'displayLabel' => $s->directorate,           'tone' => 'plain',                  'nullable' => true],
            ])->values(),
            // Opções de cada grupo pro select inline do card — `AttributeOption::options()`
            // lê de um cache único (agrupado em memória), então isto não é
            // 8 queries: é 8 leituras da mesma coleção já cacheada.
            'attributeOptions' => collect(['category', 'status', 'criticality', 'environment', 'cloud', 'contract_status', 'support_type', 'directorate'])
                ->mapWithKeys(fn (string $group) => [$group => AttributeOption::options($group)]),
        ]);
    }

    /**
     * Tom semântico do badge de criticidade — vermelho para alta/crítica,
     * âmbar para média, verde suave para baixa/desconhecida. Deriva do valor
     * cru (não do label traduzido) para não depender do texto exibido.
     */
    private function criticalityTone(): string
    {
        $value = mb_strtolower((string) $this->solution->criticality);

        return match (true) {
            in_array($value, ['high', 'critical', 'alta', 'critica', 'crítica', 'critico', 'crítico'], true) => 'crit',
            in_array($value, ['medium', 'media', 'média', 'moderada'], true)                                 => 'amber',
            default                                                                                          => 'green',
        };
    }
}
