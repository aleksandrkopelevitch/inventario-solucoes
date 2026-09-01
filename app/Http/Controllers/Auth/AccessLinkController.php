<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;

/**
 * The public end of an access link: `/access/{token}`.
 *
 * An admin grants a Person access on their page and copies a link; this is what
 * that link opens. It is the emailed invitation without the email — same
 * destination (`ResetPasswordController`), same short-lived Laravel reset token,
 * reached by a URL somebody handed over instead of by SMTP.
 *
 * **It never creates a session.** The obvious implementation authenticates the
 * holder and drops them inside the app, and that is exactly what this must not
 * do: a URL forwarded in a Teams thread would then BE the account. The link's
 * whole privilege is "you may set this account's password", which the person
 * then uses to log in like anybody else — one screen further, and a screen that
 * leaves them with a credential of their own rather than a link they have to
 * keep.
 *
 * The token is spent as soon as that password is set
 * (`ClearAccessTokenAfterPasswordReset`), so its real life is "until it works,
 * and at most `User::ACCESS_TOKEN_DAYS`".
 */
class AccessLinkController extends Controller
{
    public function show(string $token): RedirectResponse
    {
        $user = User::query()
            ->where('access_token', $token)
            ->where('access_token_expires_at', '>', now())
            ->first();

        // One message for "never existed", "already used" and "expired", on
        // purpose: telling them apart tells a stranger holding a dead URL
        // whether the account behind it is real. The login screen is where the
        // person can act — asking an admin for a fresh link is the way out, and
        // the flash says so.
        if (! $user) {
            return redirect()->route('login.create')->with(
                'error',
                'Este link de acesso expirou ou já foi usado. Peça um novo a um administrador.',
            );
        }

        // A FRESH Laravel reset token per open, which is what lets the access
        // link be reusable for days while the thing it hands over stays
        // short-lived (`config('auth.passwords.users.expire')`, 60 minutes).
        return redirect()->route('password.reset', [
            'token' => Password::createToken($user),
            'email' => $user->email,
        ]);
    }
}
