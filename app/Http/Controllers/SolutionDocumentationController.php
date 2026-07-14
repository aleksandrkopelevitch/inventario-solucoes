<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EditsDocumentation;
use App\Http\Requests\SaveDocumentationRequest;
use App\Http\Requests\UploadDocumentationMediaRequest;
use App\Models\Integration;
use App\Models\Solution;
use App\View\Components\Solutions\Documentation;
use App\View\Components\Solutions\SharePanel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Documentação rica por Solução (editor de blocos Editor.js, formato Markdown +
 * notação GitBook na coluna `solutions.documentation`). Thin — delega ao trait
 * EditsDocumentation.
 */
class SolutionDocumentationController extends Controller
{
    use EditsDocumentation;

    public function edit(Solution $solution): View
    {
        return $this->documentationView($solution, [
            'save'   => route('solutions.docs.update', $solution),
            'upload' => route('solutions.docs.media', $solution),
            'back'   => route('solutions.show', $solution),
        ], eyebrow: 'Solução', backLabel: $solution->name)->with([
            // Cobertura (antigo bloco F7) e o índice de documentações
            // relacionadas só existem na doc da própria Solution — a view
            // genérica (compartilhada com IntegrationDocumentationController)
            // os trata como opcionais via @isset.
            'coverageSolution' => $solution,
            'relatedDocs'      => $solution->integrations()->get()->map(fn (Integration $integration) => [
                'label'   => $integration->name,
                'hasDocs' => trim((string) $integration->documentation) !== '',
                'url'     => route('solutions.integrations.docs.edit', [$solution, $integration]),
            ]),
        ]);
    }

    public function update(SaveDocumentationRequest $request, Solution $solution): JsonResponse
    {
        $response = $this->persistDocumentation($request, $solution);

        // Atualiza a seção read-only inline no detalhe da solução, se o usuário
        // voltar pra lá (ajax-slot no-op se o id não estiver na página atual).
        return $response->setData($response->getData(true) + [
            'updatableSlots' => [Documentation::slot($solution->fresh())],
        ]);
    }

    public function media(UploadDocumentationMediaRequest $request, Solution $solution): JsonResponse
    {
        return $this->storeDocumentationMedia($request, $solution);
    }

    /** Gera (se ainda não existe) o token do link público e devolve o painel. */
    public function share(Solution $solution): JsonResponse
    {
        $this->authorize('update', $solution);

        if (! $solution->public_token) {
            $solution->update(['public_token' => Str::random(40)]);
        }

        return response()->json([
            'type'           => 'success',
            'message'        => 'Link público gerado.',
            'updatableSlots' => [SharePanel::slot($solution->fresh())],
        ]);
    }

    /** Revoga o link público (zera o token — o link antigo para de funcionar). */
    public function unshare(Solution $solution): JsonResponse
    {
        $this->authorize('update', $solution);

        $solution->update(['public_token' => null]);

        return response()->json([
            'type'           => 'success',
            'message'        => 'Acesso público revogado.',
            'updatableSlots' => [SharePanel::slot($solution->fresh())],
        ]);
    }
}
