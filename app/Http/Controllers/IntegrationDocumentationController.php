<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AssistsDocumentation;
use App\Http\Controllers\Concerns\EditsDocumentation;
use App\Http\Controllers\Concerns\NavigatesSolutionDocs;
use App\Http\Requests\GenerateDocumentationDraftRequest;
use App\Http\Requests\SaveDocumentationRequest;
use App\Http\Requests\UploadDocumentationMediaRequest;
use App\Models\DocumentationAiGeneration;
use App\Models\Integration;
use App\Models\Solution;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Documentação rica por Integração (editor de blocos Editor.js, formato
 * Markdown + notação GitBook na coluna `integrations.documentation`). As rotas
 * ficam sob o grupo `scopeBindings` de solucoes/{solution}/integracoes/{integration},
 * então {integration} 404a se não pertencer à {solution} da URL. Mostra a
 * mesma sidebar consolidada de SolutionDocumentationController (páginas da
 * solução + integrações — ver NavigatesSolutionDocs), pra que a doc da
 * integração pareça parte da mesma árvore, não uma tela à parte. Thin —
 * delega ao trait EditsDocumentation.
 */
class IntegrationDocumentationController extends Controller
{
    use AssistsDocumentation, EditsDocumentation, NavigatesSolutionDocs;

    public function edit(Solution $solution, Integration $integration): View
    {
        return $this->documentationView($integration, [
            'save'   => route('solutions.integrations.docs.update', [$solution, $integration]),
            'upload' => route('solutions.integrations.docs.media', [$solution, $integration]),
            'back'   => route('solutions.show', $solution),
        ], eyebrow: 'Integração · ' . $solution->name, backLabel: $integration->name)->with([
            'pagesNav'        => $this->solutionPagesNav($solution, null),
            'integrationsNav' => $this->solutionIntegrationsNav($solution, $integration),
            'createPageUrl'   => route('solutions.docs.pages.store', $solution),
            'assistPanelUrl'  => route('solutions.integrations.docs.assist.panel', [$solution, $integration]),
            'breadcrumbs'     => [
                ['label' => $solution->name, 'url' => route('solutions.show', $solution)],
                ['label' => 'Documentação', 'url' => route('solutions.docs.edit', $solution)],
            ],
        ]);
    }

    public function update(SaveDocumentationRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        return $this->persistDocumentation($request, $integration);
    }

    public function media(UploadDocumentationMediaRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        return $this->storeDocumentationMedia($request, $integration);
    }

    /* --- Assiste IA (gera o conteúdo da doc da integração por LLM) -------- */

    public function assistantPanel(Solution $solution, Integration $integration): JsonResponse
    {
        return $this->assistantPanelResponse(
            $solution,
            $integration,
            route('solutions.integrations.docs.assist.generate', [$solution, $integration]),
        );
    }

    public function generateDraft(GenerateDocumentationDraftRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        return $this->createDraft(
            $request,
            $solution,
            $integration,
            // Endpoint de status único (solutions.docs.assist.status) — serve
            // páginas e integrações; o registro carrega o próprio alvo.
            fn (DocumentationAiGeneration $g) => route('solutions.docs.assist.status', [$solution, $g]),
        );
    }
}
