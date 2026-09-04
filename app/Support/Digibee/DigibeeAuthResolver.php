<?php

namespace App\Support\Digibee;

use App\Exceptions\DigibeeApiException;
use App\Support\Gitbook\TransientHttpFailure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Resolves the Digibee platform session, environment first and the local
 * `digibeectl` config file second.
 *
 * The order is the whole point, and it is what lets one class serve two very
 * different machines. On the server the credential arrives as encrypted
 * environment variables belonging to a realm user scoped to what the lifecycle
 * actually needs; on a workstation it is already sitting in digibeectl's own
 * config, and asking a developer to copy a short-lived JWT into `.env` by hand
 * is how a feature stops being used. Environment wins so that the server can
 * never accidentally pick up a developer's broader credential from a file
 * somebody synced.
 *
 * **The config file is read, never written**, and its shape is not published
 * anywhere. `digibeectl config` manages several realms and several accounts
 * (`--auth-id`), so the session can sit nested; the lookup below tries the
 * documented field names at the root and then one nesting level, and REPORTS
 * the JSON path it used (`diagnose()`). That report is deliberate: a tolerant
 * parser that silently guesses is indistinguishable from a correct one until
 * the day it picks the wrong realm's token.
 */
class DigibeeAuthResolver
{
    /**
     * Field names to try, in order, for each credential part. The first list
     * entry is what the APLA spec names; the rest are what a config file
     * written by a different digibeectl version plausibly calls it.
     *
     * @var array<string, list<string>>
     */
    private const ALIASES = [
        'endpoint' => ['endpoint', 'url', 'baseUrl', 'host'],
        'realm'    => ['currentRealm', 'realm'],
        'jwt'      => ['jwt', 'token', 'accessToken'],
        'apikey'   => ['apikey', 'apiKey', 'authKey', 'auth_key', 'secretKey'],
    ];

    /** How deep to look for a nested session before giving up. */
    private const MAX_DEPTH = 4;

    public function resolve(): DigibeeCredentials
    {
        $file = $this->fromConfigFile();

        $resolved = [];
        $sources = [];

        foreach (['endpoint', 'realm', 'jwt', 'apikey'] as $field) {
            $fromEnv = (string) config("services.digibee.design.{$field}", '');

            if ($fromEnv !== '') {
                $resolved[$field] = $fromEnv;
                $sources[$field] = 'environment';

                continue;
            }

            $resolved[$field] = (string) ($file['values'][$field] ?? '');
            $sources[$field] = isset($file['paths'][$field])
                ? 'digibeectl config (' . $file['paths'][$field] . ')'
                : '—';
        }

        return new DigibeeCredentials(
            endpoint: rtrim($resolved['endpoint'], '/'),
            realm: $resolved['realm'],
            jwt: $resolved['jwt'],
            apikey: $resolved['apikey'],
            sources: $sources,
        );
    }

    /** Throws rather than returning something half-resolved. */
    public function credentials(): DigibeeCredentials
    {
        $credentials = $this->resolve();

        if (! $credentials->complete()) {
            throw DigibeeApiException::missingCredentials(implode(', ', $credentials->missing()));
        }

        return $credentials;
    }

    /**
     * The configured client every Digibee platform call goes through.
     *
     * `retry(..., throw: false)` is not a preference — see AGENTS.md § HTTP
     * Client: `retry()` otherwise throws a raw RequestException the instant a
     * response fails, jumping straight over the `$response->failed()` checks
     * that let the probe report WHICH route answered what. That reporting is
     * the entire product of Phase 1.
     */
    public function pendingRequest(): PendingRequest
    {
        $credentials = $this->credentials();

        return Http::baseUrl($credentials->endpoint)
            ->withHeaders($credentials->headers())
            ->timeout((int) config('services.digibee.design.timeout'))
            ->connectTimeout(5)
            ->acceptJson()
            ->asJson()
            ->retry(
                (int) config('services.digibee.design.retries'),
                (int) config('services.digibee.design.retry_sleep'),
                fn (Throwable $e) => TransientHttpFailure::matches($e),
                throw: false,
            );
    }

    public function configPath(): string
    {
        $path = (string) config('services.digibee.design.config_path');

        return $path !== '' ? $path : rtrim((string) getenv('HOME'), '/') . '/.digibeectl/config.json';
    }

    /**
     * @return array{values: array<string, string>, paths: array<string, string>}
     */
    private function fromConfigFile(): array
    {
        $path = $this->configPath();
        $empty = ['values' => [], 'paths' => []];

        if (! is_file($path) || ! is_readable($path)) {
            return $empty;
        }

        try {
            $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw DigibeeApiException::unreadableConfig($path, $e->getMessage());
        }

        if (! is_array($document)) {
            return $empty;
        }

        $session = $this->locateSession($document, $this->currentRealm($document));
        $values = [];
        $paths = [];

        foreach (self::ALIASES as $field => $aliases) {
            // The session object first, then the document root: `endpoint` in
            // particular tends to sit at the top while the token sits inside
            // the account, and reading a nested realm's token against the
            // wrong endpoint is the one combination that fails confusingly.
            foreach ([$session, ['prefix' => '', 'object' => $document]] as $scope) {
                foreach ($aliases as $alias) {
                    $value = $scope['object'][$alias] ?? null;

                    if (is_string($value) && $value !== '') {
                        $values[$field] = $value;
                        $paths[$field] = $scope['prefix'] . $alias;

                        continue 3;
                    }
                }
            }
        }

        return ['values' => $values, 'paths' => $paths];
    }

    /**
     * The nested object holding a session, plus its JSON path for reporting.
     *
     * "Holding a session" means carrying a JWT — the one field that cannot be
     * anywhere else. When the document names a current realm, an object whose
     * own realm matches it wins; that is the difference between deploying to
     * the realm the developer is working in and deploying to whichever one the
     * file happens to list first.
     *
     * **The current realm is threaded down the recursion, not re-read at each
     * level.** `currentRealm` sits at the ROOT while the accounts it selects
     * between sit inside a nested object, so a version of this that looked for
     * the pointer beside the candidates found nothing, fell through to "first
     * one that has a token", and picked a different realm's session — silently,
     * and only on a config file holding more than one.
     *
     * @param  array<mixed>  $document
     * @return array{prefix: string, object: array<mixed>}
     */
    private function locateSession(array $document, ?string $current, string $prefix = '', int $depth = 0): array
    {
        $fallback = null;

        foreach ($document as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if ($this->holdsSession($value)) {
                $candidate = ['prefix' => "{$prefix}{$key}.", 'object' => $value];

                if ($current !== null && $this->currentRealm($value) === $current) {
                    return $candidate;
                }

                $fallback ??= $candidate;

                continue;
            }

            if ($depth < self::MAX_DEPTH) {
                // A nested level may name its own current realm; otherwise it
                // inherits the one in scope.
                $deeper = $this->locateSession(
                    $value,
                    $this->currentRealm($value) ?? $current,
                    "{$prefix}{$key}.",
                    $depth + 1,
                );

                if ($deeper['object'] !== []) {
                    // A realm-matched hit deeper down beats a first-found one
                    // at this level, which is the whole point of the pointer.
                    if ($current !== null && $this->currentRealm($deeper['object']) === $current) {
                        return $deeper;
                    }

                    $fallback ??= $deeper;
                }
            }
        }

        return $fallback ?? ['prefix' => $prefix, 'object' => []];
    }

    /** @param array<mixed> $object */
    private function holdsSession(array $object): bool
    {
        foreach (self::ALIASES['jwt'] as $alias) {
            if (is_string($object[$alias] ?? null) && $object[$alias] !== '') {
                return true;
            }
        }

        return false;
    }

    /** @param array<mixed> $object */
    private function currentRealm(array $object): ?string
    {
        foreach (self::ALIASES['realm'] as $alias) {
            if (is_string($object[$alias] ?? null) && $object[$alias] !== '') {
                return $object[$alias];
            }
        }

        return null;
    }
}
