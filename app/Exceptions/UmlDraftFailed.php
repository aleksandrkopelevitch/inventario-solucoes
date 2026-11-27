<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * "Gerar com IA" on a "Diagrama UML" block had nothing to hand back.
 *
 * Only for the cases with NOTHING to show: no JSON, a refusal, nothing to
 * generate from. A diagram that came back with problems is not one of them —
 * UmlDraftService returns it with its problems listed, since a few KB of
 * mostly right JSON is worth more to the author than a retry button.
 *
 * A 422 rather than a 500, like SceneDraftFailed: nothing is broken, and
 * asking again (or saying more) is a reasonable next move. PT-BR because it is
 * read on screen.
 */
class UmlDraftFailed extends RuntimeException
{
    public static function noJson(): self
    {
        return new self('O especialista não devolveu um diagrama legível. Tente de novo.');
    }

    /** The model did not answer in time (or at all) — a retry usually works. */
    public static function unavailable(): self
    {
        return new self('O especialista demorou demais para responder. Tente de novo em instantes.');
    }

    /** The model's own "this page describes no such diagram". */
    public static function notDescribed(string $reason): self
    {
        return new self('Não dá para montar este diagrama: ' . rtrim($reason, '.') . '.');
    }

    public static function nothingToDraw(): self
    {
        return new self('Esta página ainda não tem conteúdo. Descreva o diagrama no campo ao lado do botão.');
    }

    public function render(Request $request): ?JsonResponse
    {
        return $request->wantsJson()
            ? response()->json(['message' => $this->getMessage(), 'type' => 'warning'], 422)
            : null;
    }
}
