<?php

namespace App\View\Components\Solutions;

use App\Models\AttributeOption;
use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Header of the solution's detail page (briefing 9.2 items 1 and 2: header +
 * "Operação" block). Extracted as its own component so that editing the
 * solution from its own detail page (via side panel) can also update
 * what's on screen — not just the listing.
 *
 * Renderable as an updatable slot: `DetailHeader::slot($solution)`.
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
            // Each attribute is displayed with the LABEL of its dimension (Categoria,
            // Status, …) — the value alone ("Alta", "Planejado") didn't make
            // it clear what it represented. Semantic tone via "Blocos Leo".
            // Always all 8 — even without a value: a blank attribute becomes
            // "Não informado" on the card (see detail-header.blade.php), it
            // never disappears from the grid (the gray gap was confused with a layout bug).
            // `value` is the RAW value (not the label) — the inline select
            // needs it to mark the selected option; `nullable` decides
            // whether the card accepts clearing the field back to "Não informado".
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
            // Options for each group for the card's inline select — `AttributeOption::options()`
            // reads from a single cache (grouped in memory), so this isn't
            // 8 queries: it's 8 reads of the same already-cached collection.
            'attributeOptions' => collect(['category', 'status', 'criticality', 'environment', 'cloud', 'contract_status', 'support_type', 'directorate'])
                ->mapWithKeys(fn (string $group) => [$group => AttributeOption::options($group)]),
        ]);
    }

    /**
     * Semantic tone of the criticality badge — red for high/critical,
     * amber for medium, soft green for low/unknown. Derived from the raw
     * value (not the translated label) so it doesn't depend on the displayed text.
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
