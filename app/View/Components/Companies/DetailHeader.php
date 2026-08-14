<?php

namespace App\View\Components\Companies;

use App\Enums\CompanyKind;
use App\Models\Company;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Company detail header (briefing 9.6). Extracted as its own component so
 * that editing the company from its own detail page (via side panel) also
 * updates what's on screen — and, since every datum here is click-to-edit
 * (`<x-ui.inline-edit>` → `companies.field.update`), so that each of those
 * one-field saves has a slot to answer with.
 *
 * Renderable as an updatable slot: `DetailHeader::slot($company)`.
 */
class DetailHeader extends Component
{
    use Renderable;

    public const DOM_ID = 'company-detail-header-slot';

    public function __construct(public Company $company) {}

    public static function slot(Company $company): array
    {
        return (new static($company))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.companies.detail-header', [
            'domId'   => self::DOM_ID,
            'canEdit' => auth()->user()?->can('update', $this->company) ?? false,
            'kinds'   => CompanyKind::cases(),
        ]);
    }
}
