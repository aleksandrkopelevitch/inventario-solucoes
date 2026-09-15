<?php

namespace App\Exceptions;

use Exception;

/**
 * A sign-in that Microsoft completed and this app refused.
 *
 * Distinct from a FAILED sign-in — the person proved who they are, and the
 * answer is still no. Three shapes, and each is deliberately a different
 * sentence, because an admin reading a screenshot has to be able to tell them
 * apart without asking for a log:
 *
 * - the mailbox is not one of ours (a guest, a personal account, a partner);
 * - the account exists here and was REVOKED;
 * - Entra answered without an e-mail at all.
 *
 * Not self-rendering, unlike `SecretRevealRejected`: this is caught in
 * `Auth\EntraController`, which turns it into a redirect back to the login
 * screen with a flash — the failure happens mid-redirect from an external site,
 * where a JSON body or a bare status page is not a place a person can act from.
 */
class EntraSignInRejected extends Exception
{
    /**
     * The address is outside the allowed domains.
     *
     * The message names the address on purpose: the single most common cause is
     * somebody signed into Windows with a personal or a customer account, and
     * they cannot see which one Microsoft handed over unless the screen says
     * it.
     */
    public static function foreignDomain(string $email): self
    {
        return new self(sprintf(
            'A conta %s não é uma conta Leo Madeiras. Entre com o seu e-mail corporativo.',
            $email,
        ));
    }

    /**
     * A guest (B2B) account in the Leo tenant.
     *
     * Its UPN ends in a Leo domain while the person works somewhere else, so
     * the domain check alone would let them in — this is the case that check
     * cannot see. Entra writes such a UPN as
     * `someone_theircompany.com#EXT#@leomadeiras.onmicrosoft.com`.
     */
    public static function guestAccount(): self
    {
        return new self('Contas de convidado não têm acesso à base de conhecimento.');
    }

    /**
     * The account exists here and was switched off.
     *
     * Deliberately NOT re-provisioned. Revoking soft-deletes the account
     * (`GrantPersonAccess::revoke()`), and SSO that quietly restored it would
     * make "remover acesso" mean nothing at all for anybody who still has a
     * mailbox — which is everybody it is ever used on.
     */
    public static function revoked(): self
    {
        return new self('Esse acesso foi removido. Fale com um administrador.');
    }

    /** Entra returned a profile with no usable mailbox. */
    public static function noEmail(): self
    {
        return new self('A Microsoft não retornou um e-mail para essa conta.');
    }
}
