<?php

namespace App\Support\Digibee;

use App\Exceptions\DigibeeApiException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The design API, as verified against the real realm rather than as
 * documented — Digibee publishes none of this.
 *
 * Everything here was established empirically (Fase 1, blocks A′ and A″, and
 * `docs/apla-fase-1.md` § A API, como referência keeps the full table):
 *
 * - `GET /design/realms/{realm}/pipelines` answers 200 with a bare LIST of
 *   1801 items, each embedding its whole `flowSpec` — so listing everything to
 *   find one name is a multi-megabyte download. `?name=` is honoured;
 *   `?projectId=` is IGNORED IN SILENCE and answers with everything.
 * - `POST` on that same collection is both verbs: without an `id` it CREATES,
 *   with one it UPSERTS. It answers an ENVELOPE — `{pipeline, configurations}`
 *   — not the 34-key document `GET` returns, so the new id is at
 *   `pipeline.id`.
 * - `PUT`, `PATCH` and `POST` on `/pipelines/{id}` all answer **405**: there
 *   is no update route hanging off the individual resource. That is why the
 *   upsert is a POST on the collection rather than something more RESTful
 *   looking.
 * - `projectId` is accepted and discarded on create, so a new pipeline lands
 *   in `default` whatever you ask for. This client therefore does not take a
 *   project at all: an argument that is silently dropped is worse than a
 *   missing feature, because the caller believes it worked.
 *
 * **There is no delete method here, and that is deliberate.** Nothing in the
 * platform deletes a pipeline — `digibeectl delete` covers deployments and
 * api-mgmt credentials only, and no DELETE route was ever probed — so every
 * mistaken create is a permanent draft somebody has to remove by hand in the
 * canvas. A method that looked like cleanup would be a method that cannot
 * work.
 */
class DigibeeDesignClient
{
    public function __construct(private readonly DigibeeAuthResolver $auth) {}

    /**
     * Every stored version of a pipeline with EXACTLY this name.
     *
     * The server-side filter is what keeps this cheap, and the client-side
     * exact match is what keeps it correct: nothing published says whether
     * `?name=` matches exactly or by prefix, and a filter that turns out to be
     * a prefix match would otherwise hand back `zfl-cadastro-cliente-v2` for
     * `zfl-cadastro-cliente` — and the ingestion would write a flowSpec into
     * the wrong pipeline.
     *
     * @return list<array<string, mixed>>
     */
    public function findByName(string $name): array
    {
        $body = $this->json('GET', "/design/realms/{$this->realm()}/pipelines", ['name' => $name]);

        $items = match (true) {
            array_is_list($body)               => $body,
            is_array($body['content'] ?? null) => $body['content'],
            default                            => [],
        };

        return array_values(array_filter(
            $items,
            fn ($item) => is_array($item) && ($item['name'] ?? null) === $name,
        ));
    }

    /**
     * The highest `versionMajor`/`versionMinor` pair carrying that name, which
     * is the one a person means when they name a pipeline.
     *
     * @return array<string, mixed>|null
     */
    public function latestByName(string $name): ?array
    {
        $found = $this->findByName($name);

        usort($found, fn (array $a, array $b) => [(int) ($b['versionMajor'] ?? 0), (int) ($b['versionMinor'] ?? 0)]
            <=> [(int) ($a['versionMajor'] ?? 0), (int) ($a['versionMinor'] ?? 0)]);

        return $found[0] ?? null;
    }

    /**
     * The 34-key document — a superset of the 29 the listing carries, adding
     * `projectId`, `projectName`, `configurations`, `isTracingEnabled` and
     * `tracingSamplingRate`. It is what an upsert has to send back, so the
     * ingestion reads the detail even when the listing already answered.
     *
     * @return array<string, mixed>
     */
    public function pipeline(string $id): array
    {
        $body = $this->json('GET', "/design/realms/{$this->realm()}/pipelines/{$id}");

        return is_array($body) ? $body : [];
    }

    /**
     * Creates an empty pipeline and answers its id.
     *
     * A pipeline is born `v0.0` with `draft: true`, `flowSpec: null`,
     * `canvasVersion: 0` and `metadata: {}` — the same empty shell
     * `digibeectl create pipeline` makes. It lands in the `default` project
     * regardless of what is asked (see the class docblock).
     */
    public function create(string $name, string $description = ''): string
    {
        $body = $this->json('POST', "/design/realms/{$this->realm()}/pipelines", [
            'name'        => $name,
            'description' => $description,
        ]);

        $id = is_array($body) ? ($body['pipeline']['id'] ?? $body['id'] ?? null) : null;

        if (! is_string($id) || $id === '') {
            throw DigibeeApiException::unexpectedCreateEnvelope(is_array($body) ? array_keys($body) : []);
        }

        return $id;
    }

    /**
     * Writes a whole pipeline document back, keyed by the `id` it carries.
     *
     * The `id` is not optional here even though the route accepts a body
     * without one: that is the CREATE path, and using it by accident leaves a
     * second pipeline with the same name that nothing in the platform can
     * delete.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed> the `{pipeline, configurations}` envelope
     */
    public function upsert(array $document): array
    {
        $id = $document['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw DigibeeApiException::upsertWithoutId();
        }

        $body = $this->json('POST', "/design/realms/{$this->realm()}/pipelines", $document);

        return is_array($body) ? $body : [];
    }

    /**
     * The realm's projects, each with its `amountOfPipelines`.
     *
     * Read-only and informational: a pipeline cannot be filed into one of
     * these through this API (`?projectId=` is ignored on the listing,
     * `projectId` is discarded on create, and `GET /projects/{id}/pipelines`
     * answers 403 to the credential we have).
     *
     * @return list<array<string, mixed>>
     */
    public function projects(): array
    {
        $body = $this->json('GET', "/design/realms/{$this->realm()}/projects");

        return is_array($body) && array_is_list($body) ? $body : [];
    }

    public function realm(): string
    {
        return $this->auth->credentials()->realm;
    }

    /**
     * @param  array<string, mixed>  $payload  query for GET, body for POST
     * @return array<string, mixed>|list<mixed>
     */
    private function json(string $method, string $path, array $payload = []): array
    {
        $response = $method === 'GET'
            ? Http::digibeeDesign()->get($path, $payload)
            : Http::digibeeDesign()->post($path, $payload);

        if ($response->failed()) {
            throw DigibeeApiException::fromResponse($method, $path, $response);
        }

        return $this->decoded($response, $method, $path);
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private function decoded(Response $response, string $method, string $path): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw DigibeeApiException::nonJsonBody($method, $path, $response->status());
        }

        return $body;
    }
}
