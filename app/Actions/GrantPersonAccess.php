<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Gives a `Person` an account, or takes it away again.
 *
 * Access used to be managed on a screen about nobody in particular: `people`
 * and `users` were unrelated tables, so "who can log in" was a list of emails
 * and "who is this" was a different list of humans. Granting access is now
 * something you do to a PERSON, on their own page, next to the systems they own.
 *
 * Three operations, and each one is a single verb the panel calls:
 *
 * - `grant()` — create the account, link it, and mint its access link.
 * - `link()`  — attach an account that already exists (the orphans on the
 *   accounts list, `admin@leomadeiras.com.br` among them).
 * - `revoke()` — soft-delete the account and unlink it.
 *
 * What is NOT here is a hard delete. Revoking has to stop somebody logging in,
 * which a soft delete does (Laravel's user provider applies the default scope,
 * so the next request on an existing session fails to resolve the account and
 * logs it out) while leaving their submissions and chats owned by a row that
 * still exists. Erasing an account for real is a different job with a different
 * blast radius.
 */
final class GrantPersonAccess
{
    /**
     * Creates the account for `$person` and returns it, with a fresh access
     * link already minted.
     *
     * The password is `Str::random(40)`: the column is NOT NULL and the person
     * has to choose their own, so this value exists only to be replaced and is
     * never shown to anybody. It is the same shape `UserController::store()`
     * uses for an emailed invitation — this is that invitation, handed over by
     * hand instead of by SMTP.
     */
    public function grant(Person $person, UserRole $role): User
    {
        $user = User::withTrashed()
            ->where('email', $person->email)
            ->first();

        if ($user) {
            // A soft-deleted account for this email is the normal case when
            // access was revoked and is being granted again — restoring it
            // keeps whatever that person authored (submissions, chats) attached
            // to them instead of orphaning it behind a second account. The
            // unique index on `email` would refuse the insert anyway.
            $user->restore();
            $user->update(['name' => $person->name, 'role' => $role]);
        } else {
            $user = User::create([
                'name'     => $person->name,
                'email'    => $person->email,
                'role'     => $role,
                'password' => Str::random(40),
            ]);
        }

        $this->attach($person, $user);
        $this->mintAccessToken($user);

        return $user->refresh();
    }

    /** Attaches an account that already exists to this person. */
    public function link(Person $person, User $user): void
    {
        $this->attach($person, $user);
    }

    /**
     * Ends this person's access: the account is soft-deleted and unlinked.
     *
     * The token is cleared too — a revoked account whose link still opened the
     * password screen would be a door left ajar, and the screen itself would
     * then be setting a password on a row the auth provider refuses to load.
     */
    public function revoke(Person $person): void
    {
        $user = $person->user;

        $person->user()->disassociate();
        $person->save();

        if ($user) {
            $user->forceFill(['access_token' => null, 'access_token_expires_at' => null])->save();
            $user->delete();
        }
    }

    /**
     * Mints (or replaces) the account's access link token and returns it.
     *
     * Replacing is the only way to revoke a link that was passed to the wrong
     * person, so "gerar novo link" always overwrites: `unique` on the column
     * would refuse a duplicate anyway, and two live links for one account is a
     * state nobody can reason about.
     */
    public function mintAccessToken(User $user): string
    {
        $token = Str::random(48);

        // forceFill: neither column is in `$fillable`, deliberately. The token
        // is minted here and consumed by the access route, never posted.
        $user->forceFill([
            'access_token'            => $token,
            'access_token_expires_at' => now()->addDays(User::ACCESS_TOKEN_DAYS),
        ])->save();

        return $token;
    }

    /** Clears the link without touching the account. */
    public function revokeAccessToken(User $user): void
    {
        $user->forceFill(['access_token' => null, 'access_token_expires_at' => null])->save();
    }

    private function attach(Person $person, User $user): void
    {
        // Through the relation, never `update()`: `user_id` is not in
        // `$fillable`, so granting access can never be triggered by posting a
        // field to the person's edit panel — which an EDITOR can reach, and an
        // editor may not hand out an account.
        $person->user()->associate($user);
        $person->save();
    }
}
