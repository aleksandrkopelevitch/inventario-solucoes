<?php

namespace App\View\Components\Documentation;

use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Árvore vertical (lista plana, estilo GitBook) de uma Solution: suas
 * próprias páginas ("Páginas", gerenciáveis — criar/renomear/mover/apagar) e,
 * logo abaixo, a doc de cada Integration em que ela participa
 * ("Integrações", somente link — consolidando as duas coisas numa única
 * tela, ver NavigatesSolutionDocs). Um DocumentationGroup standalone não tem
 * integrações, então `$integrations` chega vazio nesse caso e a seção some.
 * Puramente apresentacional — as URLs já vêm prontas do controller
 * (`SolutionDocumentationController`/`IntegrationDocumentationController`/
 * `DocumentationGroupPageController`), que são quem sabe os nomes de rota de
 * cada contexto.
 *
 * Slot atualizável: usado depois de mover uma página (a única ação que não
 * navega pra outra tela).
 */
class PagesNav extends Component
{
    use Renderable;

    public const DOM_ID = 'documentation-pages-nav-slot';

    /** @param  array<int, array{title: string, editUrl: string, renameUrl: string, destroyUrl: string, moveUrl: string, active: bool, hasContent: bool}>  $pages
     *  @param  array<int, array{title: string, editUrl: string, active: bool, hasContent: bool}>  $integrations */
    public function __construct(
        public array $pages,
        public array $integrations,
        public string $createPageUrl,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<int, array<string, mixed>>  $integrations
     */
    public static function slot(array $pages, array $integrations, string $createPageUrl): array
    {
        return (new static($pages, $integrations, $createPageUrl))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.documentation.pages-nav', [
            'domId'         => self::DOM_ID,
            'pages'         => $this->pages,
            'integrations'  => $this->integrations,
            'createPageUrl' => $this->createPageUrl,
        ]);
    }
}
