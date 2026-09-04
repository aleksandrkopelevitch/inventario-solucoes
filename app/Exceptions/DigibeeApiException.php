<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A Digibee platform API call that didn't answer with something usable.
 *
 * Self-contained, for the same reason GitbookApiException is (see AGENTS.md
 * § Error Handling): the consumers are an artisan command and a queued job,
 * and what a human can act on is "the credential has no DEPLOYMENT:CREATE
 * permission", not `ClientException: 403`.
 *
 * The messages carry one extra burden this app's other API exception does not.
 * Every route here is UNDOCUMENTED — Digibee publishes no design API, and the
 * "Digibee APIs" beta product covers the Pipeline Metrics API only — so a 404
 * is genuinely ambiguous: a wrong path, a renamed route, or a realm that never
 * had it. Saying so is the difference between somebody fixing a typo and
 * somebody abandoning a feature that works.
 */
class DigibeeApiException extends RuntimeException
{
    public static function missingCredentials(string $missing): self
    {
        return new self(
            "Digibee credentials incomplete — missing: {$missing}. Set DIGIBEE_ENDPOINT, "
            . 'DIGIBEE_REALM, DIGIBEE_JWT and DIGIBEE_APIKEY (encrypted, see AGENTS.md § Security), '
            . 'or point DIGIBEECTL_CONFIG at a digibeectl config file that holds them. '
            . 'Run `php artisan digibee:design:probe --diagnose` to see what resolved and from where.'
        );
    }

    /**
     * Refused rather than defaulted. A deployed pipeline's host encodes its
     * environment, so falling back to a configured one would call production
     * for an environment nobody mapped — and report it as that environment.
     */
    public static function unknownEnvironment(string $environment): self
    {
        $known = implode(', ', array_keys((array) config('services.digibee.design.runtime_hosts')));

        return new self(
            "No runtime host configured for the environment \"{$environment}\" — known: {$known}. "
            . 'A deployed pipeline is reached at https://{test|api}.godigibee.io/pipeline/{realm}/v{n}/{name}, '
            . 'so the environment is the HOST: guessing one would call another environment and label it this one.'
        );
    }

    public static function unreadableConfig(string $path, string $reason): self
    {
        return new self("digibeectl config at {$path} could not be read: {$reason}.");
    }

    public static function fromResponse(string $method, string $path, Response $response): self
    {
        $status = $response->status();

        $hint = match ($status) {
            401     => 'the JWT is invalid or expired — digibeectl session tokens are short-lived, so re-authenticate and retry',
            403     => 'authenticated, but this credential lacks the permission for that operation (see the permissions column in Digibee\'s digibeectl operations table)',
            404     => 'route not found — and since none of these routes are published, that can equally mean a wrong path, a renamed route, or a realm without the feature',
            405     => 'the route exists but not for this verb, which usually means the write path is spelled differently',
            429     => 'rate-limited by the platform; wait and retry',
            default => 'unexpected status',
        };

        $detail = $response->json('message')
            ?? $response->json('error.message')
            ?? str($response->body())->limit(200)->value();

        return new self("{$method} {$path} answered {$status} ({$hint}): {$detail}");
    }
}
