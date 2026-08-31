<?php

namespace App\Actions\Documentation;

use App\Exceptions\SecretRevealRejected;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\User;
use App\Support\Documentation\SecretText;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Hands out ONE protected value of ONE page, to a reader who is either an admin
 * or holds the caderno's secret code.
 *
 * The single place a `{% secret %}` body leaves the server, which is what lets
 * everything else — the renderer, the editor, the search index, the assistant —
 * be written as "never show it" with no exceptions to audit.
 *
 * Both callers reach it: `NotebookPageController::revealSecret` (signed in) and
 * `PublicDocumentationController::revealSecret` (magic link). They differ only
 * in who is asking, which is the `$user` argument — the rules themselves cannot
 * differ, or the public surface becomes the way around the private one.
 *
 * **The throttle is the real protection, not the code's length.** Six
 * alphanumerics is ~36 bits, guessable offline in no time; five attempts per
 * reader per twelve hours makes an online search of that space take longer than
 * the value is worth. It lives here rather than on the route as
 * `throttle:5,720` for two reasons: a middleware would count an ADMIN's
 * successful reveals against the same allowance, and a correct code has to
 * CLEAR the counter — a person who typed it wrong twice and then right must not
 * carry two failures around for twelve hours.
 */
final class RevealPageSecret
{
    public const MAX_ATTEMPTS = 5;

    /** Twelve hours, the same window `docs-secret.js` writes into localStorage. */
    public const LOCKOUT_SECONDS = 12 * 60 * 60;

    /**
     * @param  string  $throttleScope  Who is being counted: the user id when
     *                                 signed in, the IP for a magic-link
     *                                 visitor. The IP is a blunt instrument (a
     *                                 whole office shares one) and it is still
     *                                 the right one here: there is no identity
     *                                 on that surface to count instead, and the
     *                                 alternative — trusting the client's own
     *                                 localStorage counter — is not a limit at
     *                                 all.
     *
     * @throws SecretRevealRejected
     */
    public function handle(
        Notebook $notebook,
        DocumentationPage $page,
        int $ordinal,
        ?string $code,
        ?User $user,
        string $throttleScope,
    ): string {
        $value = SecretText::valueAt($page->documentation, $ordinal);

        // 404, not a refusal: an ordinal that isn't in the page is a stale
        // render (somebody edited the text under the reader), and answering
        // "wrong code" would spend one of their five attempts on it.
        abort_if($value === null, 404);

        // An admin needs no code and is not counted. They can already read
        // every value in place on the authenticated reader (`revealSecrets`),
        // so a gate here would only be theatre they have to click through.
        if ($user?->role->isAdmin()) {
            return $value;
        }

        $key = self::throttleKey($notebook, $throttleScope);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw SecretRevealRejected::throttled(RateLimiter::availableIn($key));
        }

        if (blank($notebook->secret_code)) {
            throw SecretRevealRejected::noCode();
        }

        if (! $notebook->secretCodeMatches($code)) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);

            throw SecretRevealRejected::wrongCode(RateLimiter::remaining($key, self::MAX_ATTEMPTS));
        }

        // The right code buys back the failed attempts that preceded it.
        RateLimiter::clear($key);

        return $value;
    }

    /**
     * Per caderno AND per reader. Per caderno because a code unlocks one
     * caderno, so failures against one say nothing about another; per reader so
     * one person's fumbling cannot lock out everybody else.
     */
    private static function throttleKey(Notebook $notebook, string $scope): string
    {
        return 'docs-secret:' . $notebook->id . ':' . $scope;
    }
}
