<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AssistsDocumentation;
use App\Http\Controllers\Concerns\EditsDocumentation;
use App\Http\Controllers\Concerns\NavigatesSolutionDocs;
use App\Http\Requests\SaveDocumentationRequest;
use App\Http\Requests\StoreDocumentationChatMessageRequest;
use App\Http\Requests\UploadDocumentationMediaRequest;
use App\Models\Integration;
use App\Models\Solution;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Rich documentation per Integration (Editor.js block editor, Markdown
 * format + GitBook notation in the `integrations.documentation` column).
 * The routes live under the `scopeBindings` group for
 * solucoes/{solution}/integracoes/{integration}, so {integration} 404s if it
 * doesn't belong to the URL's {solution}. Shows the same consolidated
 * sidebar as SolutionDocumentationController (solution pages + integrations
 * — see NavigatesSolutionDocs), so the integration's docs feel like part of
 * the same tree, not a separate screen. Thin — delegates to the
 * EditsDocumentation trait.
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
            // Only set here (never by SolutionDocumentationController /
            // DocumentationGroupPageController) — documentation/edit.blade.php
            // uses `isset($integration)` as the single signal to render the
            // Documentação/Diagrama tabs and mount the chain canvas.
            'solution'        => $solution,
            'integration'     => $integration,
            'pagesNav'        => $this->solutionPagesNav($solution, null),
            'integrationsNav' => $this->solutionIntegrationsNav($solution, $integration),
            'createPageUrl'   => route('solutions.docs.pages.store', $solution),
            'chatPanelUrl'    => route('solutions.integrations.docs.chat.panel', [$solution, $integration]),
            'chatResume'      => $this->chatResumeFor($solution, $integration),
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

    /* --- Documentation Assistant (chat that helps write the integration's doc) --- */

    public function chatPanel(Solution $solution, Integration $integration): JsonResponse
    {
        return $this->chatPanelResponse(
            $solution,
            $integration,
            route('solutions.integrations.docs.chat.messages.store', [$solution, $integration]),
        );
    }

    public function sendMessage(StoreDocumentationChatMessageRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        return $this->sendChatMessage($request, $solution, $integration);
    }
}
