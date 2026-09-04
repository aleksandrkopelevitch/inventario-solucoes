<?php

namespace App\Actions\Digibee;

use App\Support\Digibee\DigibeeAuthResolver;
use App\Support\Digibee\DigibeeProbeReport;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Verifies, read-only, that Digibee's platform API answers the routes the
 * autonomous lifecycle is going to be built on.
 *
 * **Every call here is a GET, and that is a property of the class rather than
 * of the arguments it happens to be given.** There is no verb parameter and no
 * write path — a probe that could POST would be a deploy trigger one flag away,
 * against a realm holding 201 live integrations, and the point of running it is
 * that nobody yet knows what these routes do.
 *
 * It reports rather than throws. A 401, a 403 and a 404 are three different
 * pieces of news here (expired session, credential too narrow, route not where
 * the spec says) and all three are worth seeing in one run beside the others —
 * which is also why `DigibeeAuthResolver::pendingRequest()` sets
 * `retry(..., throw: false)`.
 *
 * What it deliberately does NOT do is search for the route. If the documented
 * guess 404s, the honest next step is capturing the real request from the
 * canvas in browser devtools — not walking a path list against somebody else's
 * production platform, which is indistinguishable from scanning it.
 */
class ProbeDigibeeDesignApi
{
    public function __construct(private readonly DigibeeAuthResolver $auth) {}

    public function handle(?string $pipelineId = null, string $environment = 'test'): DigibeeProbeReport
    {
        $credentials = $this->auth->resolve();

        if (! $credentials->complete()) {
            return new DigibeeProbeReport(credentials: $credentials);
        }

        $realm = $credentials->realm;
        $steps = [];

        $list = $this->get(
            $steps,
            'pipeline list',
            "/design/realms/{$realm}/pipelines",
        );

        // The id to read back: the one given, else whatever the list offered.
        // Reading a pipeline somebody names is the useful case (it is the one
        // they are about to have the agent rewrite); falling back to the list
        // keeps the probe runnable with no arguments at all.
        $pipelineId ??= $this->firstPipelineId($list);

        $roundTrip = [];
        $reachedDetail = false;

        if ($pipelineId !== null) {
            $detail = $this->get(
                $steps,
                'pipeline detail',
                "/design/realms/{$realm}/pipelines/{$pipelineId}",
            );

            if ($detail?->successful()) {
                $body = $detail->json();
                $reachedDetail = is_array($body);
                $roundTrip = $reachedDetail
                    ? array_values(array_intersect(DigibeeProbeReport::ROUND_TRIP_KEYS, array_keys($body)))
                    : [];
            }
        } else {
            $steps[] = [
                'label'  => 'pipeline detail',
                'method' => 'GET',
                'path'   => "/design/realms/{$realm}/pipelines/{id}",
                'status' => null,
                'ok'     => false,
                'note'   => 'skipped: no pipeline id given and the list returned none to borrow',
                'shape'  => [],
            ];
        }

        $this->get(
            $steps,
            'deployment list',
            "/runtime/realms/{$realm}/deployments",
            ['environment' => $environment],
        );

        return new DigibeeProbeReport(
            credentials: $credentials,
            steps: $steps,
            roundTripKeys: $roundTrip,
            reachedDetail: $reachedDetail,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, string>  $query
     */
    private function get(array &$steps, string $label, string $path, array $query = []): ?Response
    {
        $response = Http::digibeeDesign()->get($path, $query);

        $steps[] = [
            'label'  => $label,
            'method' => 'GET',
            'path'   => $path . ($query === [] ? '' : '?' . http_build_query($query)),
            'status' => $response->status(),
            'ok'     => $response->successful(),
            'note'   => $this->note($response),
            'shape'  => $this->shape($response),
        ];

        return $response;
    }

    /**
     * A short, non-disclosing description of what came back. It reports the
     * SHAPE and never the content: a pipeline body carries internal hostnames
     * and, in eight of the 201 exported ones, a literal credential — none of
     * which belongs in a terminal transcript that gets pasted into a ticket.
     *
     * @return list<string>
     */
    private function shape(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            return [];
        }

        // A list envelope (`{content: [...], last: bool}` is what digibeectl's
        // own pagination looks like) versus a single object: reporting the
        // top-level keys of the FIRST element is what makes a list legible
        // without printing the list.
        if (array_is_list($body)) {
            return is_array($body[0] ?? null) ? array_keys($body[0]) : ['(list of ' . count($body) . ' non-objects)'];
        }

        return array_keys($body);
    }

    private function note(Response $response): string
    {
        if ($response->successful()) {
            $body = $response->json();

            return match (true) {
                is_array($body) && array_is_list($body) => count($body) . ' item(s)',
                is_array($body)                         => count($body) . ' key(s)',
                default                                 => 'answered ' . $response->status() . ' with a non-JSON body',
            };
        }

        return match ($response->status()) {
            401     => 'unauthenticated — the JWT is invalid or expired (digibeectl sessions are short-lived)',
            403     => 'authenticated, but this credential lacks the permission for that route',
            404     => 'not found — a wrong path, a renamed route, or a realm without the feature; capture the real call from the canvas in devtools',
            405     => 'the route exists but not for GET',
            429     => 'rate-limited',
            default => 'unexpected status',
        };
    }

    private function firstPipelineId(?Response $list): ?string
    {
        if ($list === null || ! $list->successful()) {
            return null;
        }

        $body = $list->json();

        if (! is_array($body)) {
            return null;
        }

        // Three envelopes to try, because which one this route uses is exactly
        // what is unknown: a bare list, digibeectl's `{content: [...]}`, or a
        // single object that already IS a pipeline.
        $candidates = match (true) {
            array_is_list($body)               => $body,
            is_array($body['content'] ?? null) => $body['content'],
            default                            => [$body],
        };

        foreach ($candidates as $candidate) {
            foreach (['id', '_id', 'pipelineId'] as $key) {
                $value = is_array($candidate) ? ($candidate[$key] ?? null) : null;

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }
}
