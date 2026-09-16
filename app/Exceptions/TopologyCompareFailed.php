<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The AS IS / TO BE comparison could not be produced.
 *
 * Self-contained like its two siblings (AGENTS.md § Error Handling), and 422
 * for the same reason: an empty canvas is a fact about the submission, not a
 * fault in the app.
 */
class TopologyCompareFailed extends RuntimeException
{
    public static function emptyCanvas(string $missing): self
    {
        return new self('Desenhe ' . $missing . ' antes de comparar — a comparação lê os dois canvas.');
    }

    /**
     * @param  list<string>  $problems
     */
    public static function rejected(array $problems): self
    {
        return new self(
            'Não foi possível comparar os dois desenhos: '
            . (implode(' ', array_slice($problems, 0, 2)) ?: 'o renderizador recusou a comparação.')
        );
    }

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
