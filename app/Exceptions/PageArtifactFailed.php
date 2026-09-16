<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * No artifact came out of a page.
 *
 * Self-contained, like `DiagramDraftFailed` beside it (AGENTS.md § Error
 * Handling): the consumer is an AJAX menu item, and 422 is the honest status —
 * the page said too little, or the model answered badly, and nothing is broken.
 *
 * `notDescribed()` is the interesting one: the model is explicitly ALLOWED to
 * answer "this page has no sequence in it", and that answer must arrive as
 * itself rather than as a diagram of four boxes invented to satisfy the
 * request. It is the single most useful thing this feature can say about a
 * page, so it is not flattened into a generic failure.
 */
class PageArtifactFailed extends RuntimeException
{
    public static function emptyPage(): self
    {
        return new self('Esta página ainda não tem conteúdo para desenhar.');
    }

    public static function noJson(): self
    {
        return new self('O especialista não devolveu um diagrama legível. Tente de novo.');
    }

    public static function notDescribed(string $reason): self
    {
        return new self('Não dá para montar esse diagrama a partir desta página: ' . rtrim($reason, '.') . '.');
    }

    /**
     * @param  list<string>  $problems
     */
    public static function rejected(array $problems): self
    {
        return new self(
            'O diagrama proposto não passou na validação do renderizador: '
            . implode(' ', array_slice($problems, 0, 2))
            . (count($problems) > 2 ? ' (e mais ' . (count($problems) - 2) . ')' : '')
        );
    }

    /** The sidecar itself is missing or broken — an operator problem, not an author's. */
    public static function rendererUnavailable(): self
    {
        return new self('O renderizador de diagramas não está disponível neste servidor. Avise o time de TI.');
    }

    public function render(Request $request): ?JsonResponse
    {
        return $request->wantsJson()
            ? response()->json(['message' => $this->getMessage(), 'type' => 'warning'], 422)
            : null;
    }
}
