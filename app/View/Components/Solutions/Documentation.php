<?php

namespace App\View\Components\Solutions;

use App\Models\DocumentationPage;
use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Seção resumo da documentação da solução, exibida no detalhe (F1) logo
 * abaixo das integrações — a Solution agora tem uma árvore de 1..N páginas
 * (não mais um blob único), então aqui só listamos os títulos linkando pra
 * cada página; o conteúdo completo mora na própria tela do editor
 * (`solutions.docs.edit`, que resolve/abre a primeira página).
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
            'domId' => self::DOM_ID,
            'pages' => $this->solution->pages()->get()->map(fn (DocumentationPage $page) => [
                'title'      => $page->title,
                'url'        => route('solutions.docs.page.edit', [$this->solution, $page]),
                'hasContent' => trim((string) $page->documentation) !== '',
            ]),
            'editUrl' => route('solutions.docs.edit', $this->solution),
        ]);
    }
}
