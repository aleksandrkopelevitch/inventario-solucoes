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

    /**
     * The create answered 200 with something that is not the envelope A″
     * observed. Reported rather than shrugged off, because a create whose id
     * we failed to read is a pipeline that now exists and that nothing can
     * delete — the next run would make a second one.
     *
     * @param  list<string>  $keys
     */
    public static function unexpectedCreateEnvelope(array $keys): self
    {
        return new self(
            'The pipeline create answered without an id. Expected the envelope {pipeline, configurations} '
            . 'with the id at `pipeline.id`; got keys: ' . (implode(', ', $keys) ?: '(none)') . '. '
            . 'A pipeline may have been created anyway — check the canvas before retrying, since nothing '
            . 'in the platform deletes a pipeline.'
        );
    }

    /**
     * The upsert is a POST on the COLLECTION, which is also the create — so a
     * document without an id does not fail, it silently makes a second
     * pipeline with the same name.
     */
    public static function upsertWithoutId(): self
    {
        return new self(
            'Refusing to upsert a pipeline document with no `id`: the design API uses one route for both '
            . 'verbs (POST on the collection), so this would CREATE a duplicate pipeline instead of '
            . 'updating one — and nothing in the platform can delete it afterwards.'
        );
    }

    public static function nonJsonBody(string $method, string $path, int $status): self
    {
        return new self(
            "{$method} {$path} answered {$status} with a body that is not JSON. These routes are "
            . 'undocumented, so this usually means a gateway or login page answered instead of the API.'
        );
    }

    /**
     * Refused rather than run. A synthetic test battery is hostile traffic by
     * design, and the pipelines it would hit write to real downstream systems
     * — so where it may run is the same question as where a deploy may land,
     * answered by the same configured list.
     *
     * @param  list<string>  $allowed
     */
    public static function refusedEnvironment(string $environment, array $allowed): self
    {
        return new self(
            "Refusing to run a synthetic test suite against \"{$environment}\" — allowed: "
            . (implode(', ', $allowed) ?: '(none)') . '. These cases send malformed and incomplete '
            . 'payloads on purpose, so the environments they may reach are the ones a deploy may reach '
            . '(services.digibee.design.deployable_environments).'
        );
    }

    /**
     * The guardrail §5 of the spec asked for, in the right place. Deleting a
     * pipeline is not the destructive verb here — deploying is, because
     * promotion is what reaches real traffic. Which environments may be
     * deployed to is configuration, so opening production is a deliberate edit
     * by a person rather than an argument the agent can pass.
     *
     * @param  list<string>  $allowed
     */
    public static function refusedDeployEnvironment(string $environment, array $allowed): self
    {
        return new self(
            "Refusing to deploy to \"{$environment}\" — allowed: " . (implode(', ', $allowed) ?: '(none)')
            . '. Deploying is the verb that reaches real traffic, so the environments it may reach live in '
            . 'services.digibee.design.deployable_environments and nowhere else.'
        );
    }

    /**
     * A pipeline with no released version has no URL at all — `v0` is the
     * draft state, and every deployment in the realm is v1 or above. Refusing
     * beats composing, because the composed address answers 404 and the
     * negative cases of a suite (`!5xx`) pass against it.
     */
    public static function unreleasedPipeline(string $pipelineName): self
    {
        return new self(
            "\"{$pipelineName}\" has no released version (v0 is the draft state), so it has no URL to call. "
            . 'Publish a version first — a composed /v0/ URL answers 404, and a battery of "anything but a 5xx" '
            . 'cases would pass against an endpoint that does not exist.'
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
