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
 * Five operations, and each one is a single verb a screen calls:
 *
 * - `grant()` — create the account for a person, link it, mint its access link.
 * - `invite()` — the reverse door: an e-mail arrives, and BOTH rows are made.
 * - `link()`  — attach an account that already exists (the orphans on the
 *   accounts list, `admin@leomadeiras.com.br` among them).
 * - `unlink()` — detach it again, leaving the account untouched.
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

    /**
     * Invites somebody by e-mail: the account AND their catalog row, linked.
     *
     * The reverse door of `grant()` — there, a person exists and is given an
     * account; here, an e-mail arrives and both rows are made. It creates the
     * Person because the alternative was an ORPHAN, and orphans are what made
     * "vincular uma conta que já existe" a normal path instead of a repair: a
     * picker of accounts labelled by `users.name` reads as linking a person to
     * another person, which is a question the app should not be asking. The two
     * tables stay separate (105 of 108 catalog rows have no e-mail and belong
     * nowhere near the auth table, and `people` is writable by an EDITOR while
     * an account is the admin's), but from now on a NEW account arrives with its
     * human already attached.
     *
     * An existing catalog row with that e-mail is reused rather than duplicated
     * — the invited person is very often already filed as a contact.
     * `InviteUserRequest` refuses the one case this cannot resolve honestly (that
     * person already holds a different account), so there is no half-state here.
     */
    public function invite(string $name, string $email, UserRole $role): User
    {
        $user = User::create([
            'name'  => $name,
            'email' => $email,
            'role'  => $role,
            // Unusable until the invite mail's link sets a real one: the column
            // is NOT NULL and the invited person never chooses this value. Same
            // shape as `grant()`, which hands the link over by hand instead.
            'password' => Str::random(40),
        ]);

        $person = $this->personFor($name, $email);

        $this->attach($person, $user);

        // Set in memory rather than eager-loaded: the caller reports whose
        // account this is, and the person is already in hand (§ Strict mode).
        return $user->refresh()->setRelation('person', $person);
    }

    /** Attaches an account that already exists to this person. */
    public function link(Person $person, User $user): void
    {
        $this->attach($person, $user);
    }

    /**
     * Undoes `link()`: says these two are NOT the same human, and stops there.
     *
     * This is deliberately not `revoke()`. Linking is a statement about
     * identity — "the account that logs in as X belongs to this catalog row" —
     * and a statement can be wrong without the account being wrong. For a while
     * the only way back was "Remover acesso", so correcting a mistaken link
     * soft-deleted the account it named: linking `admin@leomadeiras.com.br` to a
     * person and then undoing it locked the seeded admin out of the app, with
     * the button reading as the inverse of the one that had just been pressed.
     *
     * So the account is left exactly as it was — role, password, access link —
     * and becomes an orphan on the roster again, where it can be linked to
     * somebody else or switched off on purpose.
     */
    public function unlink(Person $person): void
    {
        $person->user()->disassociate();
        $person->save();
    }

    /** Ends this person's access. Delegates, so both entry points behave alike. */
    public function revoke(Person $person): void
    {
        $user = $person->user;

        $this->unlink($person);

        if ($user) {
            $this->revokeAccount($user);
        }
    }

    /**
     * Ends an ACCOUNT's access, whether or not a person is attached.
     *
     * The second entry point exists because an account does not need a Person,
     * and the orphans are exactly the ones that had nowhere to be revoked from:
     * revoking used to live only on a person's card, so `admin@leomadeiras.com.br`
     * could have its role changed on the roster and could not be switched off
     * anywhere. Same soft delete, same cleared token, one behaviour.
     *
     * The token is cleared for a reason of its own: a revoked account whose link
     * still opened the password screen would be a door left ajar, and that
     * screen would then be setting a password on a row the auth provider
     * refuses to load.
     */
    public function revokeAccount(User $user): void
    {
        // A person can be attached even when the caller reached the account
        // directly (the roster), and leaving a dangling `user_id` would point
        // the catalog at a soft-deleted row.
        $user->loadMissing('person');

        if ($user->person) {
            $user->person->user()->disassociate();
            $user->person->save();
        }

        $user->forceFill(['access_token' => null, 'access_token_expires_at' => null])->save();
        $user->delete();
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

    /**
     * The catalog row for this e-mail, created if nobody is filed under it.
     *
     * Matched by folded EQUALITY (`Person::withEmail()`), so an invite typed
     * `Admin@Leo…` finds the person filed as `admin@leo…` instead of creating a
     * second row for the same human.
     */
    private function personFor(string $name, string $email): Person
    {
        return Person::withEmail($email)->first() ?? Person::create([
            'name'  => $name,
            'email' => $email,
            'slug'  => Person::uniqueSlug($name),
        ]);
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
