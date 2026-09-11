<?php

namespace App\Actions\Digibee;

use App\Exceptions\DigibeeApiException;
use App\Support\Digibee\DigibeeAuthResolver;
use App\Support\Digibee\Testing\CaseResult;
use App\Support\Digibee\Testing\EndpointCredential;
use App\Support\Digibee\Testing\PipelineTestCase;
use App\Support\Digibee\Testing\PipelineTestSuite;
use App\Support\Digibee\Testing\SuiteRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Fires a test matrix at a deployed pipeline and reads the answers back.
 *
 * This is the half of block D that needs no deployment to be correct: the
 * matrix comes from `BuildPipelineTestMatrix`, the evaluation from
 * `PipelineTestCase::evaluate()`, and what sits between them is one HTTP call
 * per case. Written and tested against a faked client, it leaves block D with
 * "point it at a deployment" rather than "build a subsystem".
 *
 * Four properties, and each one is a way this could quietly lie:
 *
 * - **It refuses an environment outside
 *   `services.digibee.design.deployable_environments`.** A synthetic battery
 *   is deliberately hostile traffic — malformed payloads, missing fields,
 *   whatever a branch needs — and the pipelines it hits write to SAP, VTEX and
 *   BigQuery. "May deploy here" and "may fire this at it" are the same
 *   question, so they share one list rather than two that must be kept in
 *   step.
 * - **It never sends a BLOCKED case.** Those carry placeholders that NAME a
 *   field (`"<cpf>"`), which is exactly what E refused to pass off as data.
 *   Sending them would put invented values into a real downstream system and
 *   report the rejection as a pipeline defect.
 * - **It does not retry.** `DigibeeAuthResolver::pendingRequest()` retries
 *   transient failures because a design read is idempotent; a test case is
 *   not. A 500 is the SIGNAL here, and re-firing a POST that already half-ran
 *   duplicates whatever it wrote before failing.
 * - **It answers with three populations** (passed / failed / never sent) and
 *   flags a run that was refused at the door — see
 *   `SuiteRun::refusedForCredentials()`.
 */
class RunPipelineTestSuite
{
    public function __construct(private readonly DigibeeAuthResolver $auth) {}

    /**
     * @param  string|null  $endpoint  the URL the PLATFORM reported for this
     *                                 deployment (`Deployment::endpoint()`), which beats composing one
     */
    public function handle(
        PipelineTestSuite $suite,
        ?EndpointCredential $credential = null,
        ?string $endpoint = null,
    ): SuiteRun {
        $allowed = (array) config('services.digibee.design.deployable_environments');

        if (! in_array($suite->environment, $allowed, true)) {
            throw DigibeeApiException::refusedEnvironment($suite->environment, $allowed);
        }

        $realm = $this->auth->resolve()->realm;

        if ($realm === '') {
            throw DigibeeApiException::missingCredentials('realm');
        }

        // The platform reports the URL it assigned in
        // `deploymentStatus.trigger`; composing one is the fallback for a
        // pipeline that has not been deployed yet. Preferring the reported one
        // removes the failure the whole environment-to-host map exists to
        // prevent — a URL assembled wrong calls another environment and labels
        // it this one. `endpointUrl()` still throws for an unmapped
        // environment rather than falling back to some surviving host.
        $url = $endpoint ?? $suite->endpointUrl($realm);

        $results = [];

        foreach ($suite->runnable() as $case) {
            $results[] = $this->run($case, $url, $credential);
        }

        return new SuiteRun(
            suite: $suite,
            url: $url,
            results: $results,
            skipped: $suite->blocked(),
            authenticated: $credential !== null,
        );
    }

    private function run(PipelineTestCase $case, string $url, ?EndpointCredential $credential): CaseResult
    {
        $request = Http::withHeaders([...$case->headers, ...($credential?->headers() ?? [])])
            ->timeout((int) config('services.digibee.design.timeout'))
            ->connectTimeout(5);

        try {
            $response = match (true) {
                // A STRING body is sent raw: it is the only way to express the
                // malformed-payload case, and encoding it would repair the
                // very thing being tested.
                is_string($case->body) => $request->withBody($case->body, $case->headers['Content-Type'] ?? 'application/json')
                    ->send($case->method, $url),
                $case->body === null => $request->send($case->method, $url),
                default              => $request->send($case->method, $url, ['json' => $case->body]),
            };
        } catch (ConnectionException $e) {
            // A pipeline that never answers is a result, not an exception to
            // abort the suite with: the remaining cases still have something
            // to say, and a timeout is itself a finding about this one. No
            // headers are passed because there are none — a media-type claim
            // then reports "veio nenhum", which is true.
            return $case->evaluate(0, null, []);
        }

        return $case->evaluate($response->status(), $response->json() ?? $response->body(), $response->headers());
    }
}
