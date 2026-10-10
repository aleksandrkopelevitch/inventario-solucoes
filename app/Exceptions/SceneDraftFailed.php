<?php

namespace App\Exceptions;

use App\Enums\SceneType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The model didn't produce a scene the page can draw.
 *
 * The twin of DiagramDraftFailed, and kept apart from it because every message
 * names a different thing on screen — the scene's own noun (SceneType), never
 * "o diagrama".
 * A 422 rather than a 500 for the same reason: nothing is broken — the page
 * said too little, or the model answered badly, and asking again is a
 * reasonable next move. PT-BR because it is read on screen.
 */
class SceneDraftFailed extends RuntimeException
{
    public static function noJson(): self
    {
        return new self('O especialista não devolveu uma resposta legível. Tente de novo.');
    }

    /** The model did not answer in time (or at all) — a retry usually works. */
    public static function unavailable(): self
    {
        return new self('O especialista demorou demais para responder. Tente de novo em instantes.');
    }

    /** @param  list<string>  $problems */
    public static function invalidDraft(SceneType $type, array $problems): self
    {
        return new self(
            $type->proposal() . ' não passou na validação: '
            . implode(' ', array_slice($problems, 0, 3))
            . (count($problems) > 3 ? ' (e mais ' . (count($problems) - 3) . ')' : '')
        );
    }

    /**
     * The prompt lets the model say "this page describes no sequence of
     * steps" (or no change), and that answer is worth more than a figure
     * invented to satisfy the button.
     */
    public static function notDescribed(SceneType $type, string $reason): self
    {
        return new self('Não dá para montar ' . $type->noun() . ' a partir desta página: ' . rtrim($reason, '.') . '.');
    }

    public static function emptyPage(SceneType $type): self
    {
        return new self('Esta página ainda não tem conteúdo para montar ' . $type->noun() . '.');
    }

    public function render(Request $request): ?JsonResponse
    {
        return $request->wantsJson()
            ? response()->json(['message' => $this->getMessage(), 'type' => 'warning'], 422)
            : null;
    }
}
