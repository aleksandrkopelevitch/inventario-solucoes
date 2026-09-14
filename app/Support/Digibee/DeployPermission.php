<?php

namespace App\Support\Digibee;

/**
 * Whether a credential may deploy to an environment, read off the token's own
 * ACL instead of discovered as a 403.
 *
 * It lives here rather than inside `DeployPipeline` because the promotion gate
 * has to ask the SAME question one step earlier: a gate that only learns the
 * answer by attempting the deploy is not a gate. Two copies of this rule would
 * be two different answers to "may we promote", and the divergence would show
 * up as a gate that waves a promotion through and a deploy that then refuses
 * it — or, far worse, the reverse.
 *
 * A digibeectl TOKEN carries its permissions inside its own JWT, environment
 * scoping included (`DEPLOYMENT:CREATE{ENV=TEST}`), which is what makes this
 * answerable offline. An interactive session declares no roles at all and is
 * left to the platform: guessing there would refuse a deploy that would have
 * worked.
 */
final class DeployPermission
{
    /**
     * The reason this credential cannot deploy there, or null when it can (or
     * when it declares nothing and the platform decides).
     *
     * @param  list<string>  $roles  what the credential declares
     */
    public static function denial(array $roles, string $environment, bool $redeploy = true): ?string
    {
        if ($roles === []) {
            return null;
        }

        $wanted = strtoupper($environment);
        $grants = ['DEPLOYMENT:CREATE', "DEPLOYMENT:CREATE{ENV={$wanted}}"];

        if ($redeploy) {
            $grants[] = 'DEPLOYMENT:CREATE:REDEPLOY';
            $grants[] = "DEPLOYMENT:CREATE:REDEPLOY{ENV={$wanted}}";
        }

        foreach ($grants as $grant) {
            if (in_array($grant, $roles, true)) {
                return null;
            }
        }

        return "O token não declara permissão de deploy em \"{$environment}\". "
            . 'Ele tem: ' . implode(', ', $roles) . '. '
            . 'Isso é lido do próprio token, antes da chamada — a plataforma responderia 403.';
    }
}
