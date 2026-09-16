<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The model didn't produce a topology this app can draw.
 *
 * Self-contained (see AGENTS.md § Error Handling): the one consumer is an AJAX
 * button, and what the person needs on screen is why nothing was created — not
 * a 500. Every message below is PT-BR because it is read on screen.
 *
 * It renders as a 422 rather than a 500 on purpose: nothing is broken here. The
 * page said too little, or the model answered badly, and pressing the button
 * again is a reasonable next move.
 */
class DiagramDraftFailed extends RuntimeException
{
    public static function noJson(): self
    {
        return new self('O especialista não devolveu um diagrama legível. Tente de novo.');
    }

    /**
     * The shape was still wrong after the repair round. The problems are the
     * validator's own sentences, which name the offending block — an operator
     * reading "o id X não existe" at least learns the page confused the model,
     * and the same text is what the repair round was given.
     *
     * @param  list<string>  $problems
     */
    public static function invalidDraft(array $problems): self
    {
        return new self(
            'O diagrama proposto não passou na validação: '
            . implode(' ', array_slice($problems, 0, 3))
            . (count($problems) > 3 ? ' (e mais ' . (count($problems) - 3) . ')' : '')
        );
    }

    public static function emptyPage(): self
    {
        return new self('Esta página ainda não tem conteúdo para desenhar.');
    }

    public function render(Request $request): ?JsonResponse
    {
        return $request->wantsJson()
            ? response()->json(['message' => $this->getMessage(), 'type' => 'warning'], 422)
            : null;
    }
}
