<?php

namespace App\View\Components\Companies;

use App\Models\Company;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Cabeçalho do detalhe da empresa (briefing 9.6). Extraído como componente
 * próprio para que editar a empresa a partir da sua própria página de
 * detalhe (via side panel) também atualize o que está na tela.
 *
 * Renderável como slot atualizável: `DetailHeader::slot($company)`.
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
        return view('components.companies.detail-header', ['domId' => self::DOM_ID]);
    }
}
