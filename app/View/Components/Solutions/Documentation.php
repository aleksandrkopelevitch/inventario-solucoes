<?php

namespace App\View\Components\Solutions;

use App\Models\Solution;
use App\Support\GitbookRenderer;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Seção read-only da documentação da solução, exibida no detalhe (F1) logo
 * abaixo das integrações. Renderiza o Markdown + notação GitBook via
 * GitbookRenderer dentro de `.html-content`. O botão "Editar/Adicionar
 * documentação" (admin) leva à página do editor de blocos.
 *
 * Slot atualizável: `Documentation::slot($solution)` — o save do editor o
 * devolve pra manter esta seção fresca se o usuário voltar ao detalhe.
 */
class Documentation extends Component
{
    use Renderable;

    public const DOM_ID = 'solution-documentation-slot';

    public function __construct(public Solution $solution) {}

    public static function slot(Solution $solution): array
    {
        return (new static($solution))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.solutions.documentation', [
            'domId'   => self::DOM_ID,
            'html'    => app(GitbookRenderer::class)->render($this->solution->documentation),
            'editUrl' => route('solutions.docs.edit', $this->solution),
        ]);
    }
}
