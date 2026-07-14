<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EditsDocumentation;
use App\Http\Requests\SaveDocumentationRequest;
use App\Http\Requests\UploadDocumentationMediaRequest;
use App\Models\Integration;
use App\Models\Solution;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Documentação rica por Integração (editor de blocos Editor.js, formato
 * Markdown + notação GitBook na coluna `integrations.documentation`). As rotas
 * ficam sob o grupo `scopeBindings` de solucoes/{solution}/integracoes/{integration},
 * então {integration} 404a se não pertencer à {solution} da URL. Thin — delega
 * ao trait EditsDocumentation.
 */
class IntegrationDocumentationController extends Controller
{
    use EditsDocumentation;

    public function edit(Solution $solution, Integration $integration): View
    {
        return $this->documentationView($integration, [
            'save'   => route('solutions.integrations.docs.update', [$solution, $integration]),
            'upload' => route('solutions.integrations.docs.media', [$solution, $integration]),
            'back'   => route('solutions.show', $solution),
        ], eyebrow: 'Integração · ' . $solution->name, backLabel: $solution->name);
    }

    public function update(SaveDocumentationRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        return $this->persistDocumentation($request, $integration);
    }

    public function media(UploadDocumentationMediaRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        return $this->storeDocumentationMedia($request, $integration);
    }
}
