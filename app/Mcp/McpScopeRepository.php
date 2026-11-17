<?php

namespace App\Mcp;

use App\Enums\AccessModule;
use App\Models\User;
use Laravel\Passport\Bridge\ScopeRepository;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

/**
 * Passport's scope repository, with one rule added: `mcp:write` is never
 * granted to an account that is an Editor of no module.
 *
 * `finalizeScopes()` is the hook league/oauth2-server calls when it issues an
 * authorization code AND on every refresh, so this one place covers both the
 * first connection and the long tail: an Editor demoted to Reader loses the
 * scope on the next refresh of their token. Writing is checked live anyway
 * (`Actor::canWrite()` asks `canEdit()` per request), so this is not the wall —
 * it is what keeps a token from CARRYING a permission its holder cannot use,
 * and the consent screen from naming one.
 */
class McpScopeRepository extends ScopeRepository
{
    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null,
    ): array {
        $scopes = parent::finalizeScopes($scopes, $grantType, $clientEntity, $userIdentifier, $authCodeId);

        if (self::mayWrite($userIdentifier === null ? null : User::find($userIdentifier))) {
            return $scopes;
        }

        return array_values(array_filter(
            $scopes,
            fn (ScopeEntityInterface $scope) => $scope->getIdentifier() !== OAuth::WRITE_SCOPE,
        ));
    }

    /**
     * Whether `$user` may hold `mcp:write` at all: an Editor of at least one
     * module the server has a writing tool for. An Editor of the Especialista
     * alone has nothing to write here, so the consent screen does not ask them.
     */
    public static function mayWrite(?User $user): bool
    {
        return $user !== null && self::writableModules($user) !== [];
    }

    /**
     * The modules `$user` could change through this server.
     *
     * @return list<AccessModule>
     */
    public static function writableModules(User $user): array
    {
        return array_values(array_filter(
            app(ToolRegistry::class)->writableModules(),
            fn (AccessModule $module) => $user->canEdit($module),
        ));
    }
}
