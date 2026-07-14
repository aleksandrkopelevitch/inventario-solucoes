<?php

namespace App\Http\Controllers\Concerns;

use App\Models\DocumentationPage;
use App\Models\Integration;
use App\Models\Solution;

/**
 * Consolida, numa única árvore de navegação, as páginas de uma Solution
 * (`DocumentationPage`, gerenciáveis: criar/renomear/mover/apagar) e a
 * documentação de cada Integration em que ela participa (single-page,
 * somente link — não é gerenciável a partir daqui). Usado tanto por
 * `SolutionDocumentationController` (navegando pelas próprias páginas)
 * quanto por `IntegrationDocumentationController` (navegando pela doc de
 * uma integração), pra que os dois mostrem exatamente a mesma sidebar —
 * "uma página por solução", como pedido.
 */
trait NavigatesSolutionDocs
{
    /** @return array<int, array<string, mixed>> */
    private function solutionPagesNav(Solution $solution, ?DocumentationPage $active): array
    {
        return $solution->pages()->get()->map(fn (DocumentationPage $page) => [
            'title'      => $page->title,
            'editUrl'    => route('solutions.docs.page.edit', [$solution, $page]),
            'renameUrl'  => route('solutions.docs.pages.rename', [$solution, $page]),
            'destroyUrl' => route('solutions.docs.pages.destroy', [$solution, $page]),
            'moveUrl'    => route('solutions.docs.pages.move', [$solution, $page]),
            'active'     => $active?->is($page) ?? false,
            'hasContent' => trim((string) $page->documentation) !== '',
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function solutionIntegrationsNav(Solution $solution, ?Integration $active): array
    {
        return $solution->integrations()->get()->map(fn (Integration $integration) => [
            'title'      => $integration->name,
            'editUrl'    => route('solutions.integrations.docs.edit', [$solution, $integration]),
            'active'     => $active?->is($integration) ?? false,
            'hasContent' => trim((string) $integration->documentation) !== '',
        ])->all();
    }
}
