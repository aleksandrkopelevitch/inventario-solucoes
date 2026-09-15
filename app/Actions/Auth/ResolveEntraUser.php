<?php

namespace App\Actions\Auth;

use App\Enums\UserRole;
use App\Exceptions\EntraSignInRejected;
use App\Models\User;
use App\Support\Auth\EntraSso;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Turns a profile Microsoft has just attested into the `User` row this app will
 * sign in — creating it on a first visit, recognising it on every visit after.
 *
 * Three questions, in this order, and the order is the design:
 *
 * 1. **Is this person allowed in at all?** A tenant is not a payroll. It holds
 *    guests, service identities and whatever domains IT has attached, so
 *    "authenticated by our tenant" has to be narrowed to "has a Leo mailbox"
 *    before anything is written.
 * 2. **Do we already know them?** By `entra_id` first — the `oid`, which
 *    survives a rename — and by e-mail exactly once, on the visit that links
 *    the two. That second match is what keeps an editor who was invited last
 *    year from arriving as a brand-new reader with their own history detached.
 * 3. **Otherwise, provision them** as a `Reader`: the knowledge base and
 *    nothing else.
 *
 * What it never does is RESTORE a revoked account. That is the one case where
 * "recognise them and let them in" is exactly wrong — see
 * `EntraSignInRejected::revoked()`.
 */
final class ResolveEntraUser
{
    /**
     * @throws EntraSignInRejected when Microsoft's answer is valid and this app
     *                             still refuses it
     */
    public function handle(SocialiteUser $profile): User
    {
        $email = $this->email($profile);
        $oid = (string) $profile->getId();

        if ($existing = User::withTrashed()->where('entra_id', $oid)->first()) {
            return $this->signInExisting($existing, $email, $profile);
        }

        // Matched on e-mail EQUALITY, folded — not `whereFolded`, which is the
        // app's substring search and would match `ana@…` against `joana@…`.
        // Case only: an address is ASCII, so accent folding has nothing to do
        // here, and `entra_id` is what identifies this account from now on.
        $byEmail = User::withTrashed()->whereRaw('lower(email) = ?', [Str::lower($email)])->first();

        if ($byEmail) {
            return $this->signInExisting($byEmail, $email, $profile, link: true);
        }

        // `forceFill`, not `create`: `entra_id` is deliberately outside
        // `$fillable` (§ the model), and the casts still apply — `password`
        // is hashed on the way in exactly as it would be either way.
        $user = new User;

        $user->forceFill([
            'name'  => $this->name($profile, $email),
            'email' => $email,
            // The floor tier, always. Whoever should be more than a reader is
            // promoted by an admin on the Usuários panel — a role this app
            // derived from a claim in somebody else's directory would be a
            // permission this app does not actually control.
            'role' => UserRole::Reader,
            // Unusable and never shown, the same shape `GrantPersonAccess`
            // writes: the column is NOT NULL and this person authenticates
            // somewhere else entirely. They can still set one through
            // "esqueci minha senha" if SSO is ever switched off.
            'password' => Str::random(40),
            'entra_id' => $oid,
        ])->save();

        return $user;
    }

    /**
     * An account we already hold. Its ROLE is never touched — an admin who
     * signs in through SSO stays an admin, and a reader who was promoted last
     * week is not demoted by their next sign-in.
     */
    private function signInExisting(User $user, string $email, SocialiteUser $profile, bool $link = false): User
    {
        if ($user->trashed()) {
            throw EntraSignInRejected::revoked();
        }

        $user->forceFill(array_filter([
            'entra_id' => $link ? (string) $profile->getId() : null,
            // The mailbox is the directory's to change, and this app's copy of
            // it is what the account is found by next time. The NAME is only
            // refreshed when ours is still the placeholder an invite left.
            'email' => $email !== $user->email ? $email : null,
        ]))->save();

        return $user;
    }

    /**
     * The mailbox this sign-in is for.
     *
     * `mail` before `userPrincipalName`: the UPN is a sign-in name and is
     * regularly not an address anybody receives mail at, while `mail` is the
     * real one. The UPN is still the fallback, because a tenant can leave
     * `mail` empty.
     *
     * The `#EXT#` check is the one this cannot do without. A guest invited into
     * the Leo tenant gets a UPN in a Leo domain — the domain check would pass —
     * and `#EXT#` is the marker Entra puts in every one of them.
     */
    private function email(SocialiteUser $profile): string
    {
        $raw = $profile->getRaw();
        $upn = (string) ($raw['userPrincipalName'] ?? '');

        if (str_contains(Str::upper($upn), '#EXT#')) {
            throw EntraSignInRejected::guestAccount();
        }

        $email = Str::lower(trim((string) ($raw['mail'] ?? '')) ?: trim($upn));

        if (blank($email)) {
            throw EntraSignInRejected::noEmail();
        }

        if (! EntraSso::allows($email)) {
            throw EntraSignInRejected::foreignDomain($email);
        }

        return $email;
    }

    /** Their display name, or the mailbox's local part when the directory has none. */
    private function name(SocialiteUser $profile, string $email): string
    {
        return trim((string) ($profile->getName() ?? '')) ?: Str::before($email, '@');
    }
}
