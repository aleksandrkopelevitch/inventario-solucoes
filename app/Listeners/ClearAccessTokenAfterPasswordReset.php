<?php

namespace App\Listeners;

use Illuminate\Auth\Events\PasswordReset;

/**
 * Spends the account's access link the moment its password is set.
 *
 * The link that `GrantPersonAccess` mints is deliberately long-lived (7 days)
 * and reusable, because it is handed over by hand and a single-use link opened
 * on the wrong device is a support request. That generosity is only safe while
 * the link leads to the password screen of an account nobody has claimed yet —
 * left alive afterwards it would be a seven-day password-reset link for a live
 * account, i.e. account takeover for anybody the URL was ever forwarded to.
 *
 * So the link's real lifetime is "until it works, and at most 7 days".
 *
 * It listens to `PasswordReset` rather than living inside
 * `ResetPasswordController` for two reasons: that event already exists and is
 * already fired there, and the ordinary "esqueci minha senha" flow fires it
 * too — a person who resets their password by email should also invalidate an
 * access link they were sent, and neither path should have to remember to.
 */
class ClearAccessTokenAfterPasswordReset
{
    public function handle(PasswordReset $event): void
    {
        $user = $event->user;

        if (blank($user->access_token)) {
            return;
        }

        $user->forceFill(['access_token' => null, 'access_token_expires_at' => null])->save();
    }
}
