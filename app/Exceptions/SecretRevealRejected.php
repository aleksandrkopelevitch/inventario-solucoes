<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A refused attempt to reveal a protected value in a documentation page
 * (App\Actions\Documentation\RevealPageSecret).
 *
 * Self-contained, per § Error Handling: it authors its own reader-facing PT-BR
 * message and its own status, so no controller has to catch it and
 * `bootstrap/app.php` has nothing to learn. `render()` on the exception itself
 * runs BEFORE `prepareException()` gets a chance to flatten it into a generic
 * `HttpException` — which is exactly the trap the AuthorizationException
 * renderer fell into.
 *
 * Never reported: a mistyped code is the feature working, not an incident, and
 * five of them per person per twelve hours is not a log worth having.
 */
class SecretRevealRejected extends Exception
{
    private function __construct(
        string $message,
        private readonly int $status,
        private readonly string $type,
    ) {
        parent::__construct($message);
    }

    /**
     * The code was wrong. 422 rather than 403: the request was allowed, the
     * VALUE submitted with it was not — and the client needs to tell the two
     * apart, because one reopens the field and the other does not.
     *
     * The number of remaining attempts is stated on purpose. It is what makes
     * the twelve-hour lockout land as a consequence rather than as a bug, and
     * it discloses nothing: the limit is a published rule, not a secret.
     */
    public static function wrongCode(int $attemptsLeft): self
    {
        return new self(
            $attemptsLeft > 0
                ? sprintf(
                    'Código incorreto. %s antes do bloqueio por 12 horas.',
                    $attemptsLeft === 1 ? 'Resta 1 tentativa' : "Restam {$attemptsLeft} tentativas",
                )
                : 'Código incorreto. Novas tentativas estão bloqueadas por 12 horas.',
            422,
            'warning',
        );
    }

    /** Out of attempts. 429, with the wait in whole hours — the window is twelve of them. */
    public static function throttled(int $secondsUntilRetry): self
    {
        $hours = max(1, (int) ceil($secondsUntilRetry / 3600));

        return new self(
            "Muitas tentativas incorretas. Tente novamente em aproximadamente {$hours}h ou peça o valor a um administrador.",
            429,
            'error',
        );
    }

    /** A caderno with no code cannot be unlocked by anybody but an admin. */
    public static function noCode(): self
    {
        return new self(
            'Este caderno ainda não tem um código de leitura. Peça a um administrador para gerar um.',
            422,
            'warning',
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'type'    => $this->type,
            'message' => $this->getMessage(),
        ], $this->status);
    }

    public function report(): bool
    {
        return false;
    }
}
