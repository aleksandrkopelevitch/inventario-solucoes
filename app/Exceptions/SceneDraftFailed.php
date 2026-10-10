<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The model didn't produce a scene the page can draw.
 *
 * The twin of DiagramDraftFailed, and kept apart from it because every message
 * names a different thing on screen ("o fluxo em etapas", not "o diagrama").
 * A 422 rather than a 500 for the same reason: nothing is broken — the page
 * said too little, or the model answered badly, and asking again is a
 * reasonable next move. PT-BR because it is read on screen.
 */
class SceneDraftFailed extends RuntimeException
{
    public static function noJson(): self
    {
        return new self('O especialista não devolveu um fluxo legível. Tente de novo.');
    }

    /** @param  list<string>  $problems */
    public static function invalidDraft(array $problems): self
    {
        return new self(
            'O fluxo proposto não passou na validação: '
            . implode(' ', array_slice($problems, 0, 3))
            . (count($problems) > 3 ? ' (e mais ' . (count($problems) - 3) . ')' : '')
        );
    }

    /**
     * The prompt lets the model say "this page describes no sequence of
     * steps", and that answer is worth more than three steps invented to
     * satisfy the button.
     */
    public static function notDescribed(string $reason): self
    {
        return new self('Não dá para montar um fluxo em etapas a partir desta página: ' . rtrim($reason, '.') . '.');
    }

    public static function emptyPage(): self
    {
        return new self('Esta página ainda não tem conteúdo para montar um fluxo.');
    }

    public function render(Request $request): ?JsonResponse
    {
        return $request->wantsJson()
            ? response()->json(['message' => $this->getMessage(), 'type' => 'warning'], 422)
            : null;
    }
}
