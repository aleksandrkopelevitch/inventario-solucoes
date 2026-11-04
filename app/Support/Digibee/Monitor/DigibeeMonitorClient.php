<?php

namespace App\Support\Digibee\Monitor;

use App\Exceptions\DigibeeApiException;
use App\Support\Gitbook\TransientHttpFailure;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Reads what Digibee's Monitor screen reads: completed executions, log lines
 * searched across executions, and the logs of one execution, in any
 * environment.
 *
 * Every route here is UNDOCUMENTED. They were read off the web app itself on
 * 2026-11-04 — the Executions tab's request (captured in DevTools) and the
 * `logsExport` chunk of its public bundle — because Digibee publishes no API
 * for logs and no digibeectl token may carry LOG:READ.
 *
 * The session this replays is a person's whole role, production deletes
 * included, so the client is a closed list: `ROUTES` is every call it can
 * make, all reads, and `call()` refuses anything else before a byte leaves.
 * Adding a route is an edit here, reviewed — never a parameter.
 */
class DigibeeMonitorClient
{
    /** method + path pattern; `{realm}` is the only placeholder. */
    private const ROUTES = [
        'POST /monitor/api/v1/executions',
        'POST /monitor/api/v1/pipeline-logs',
        'GET /runtime/realms/{realm}/logs/export',
    ];

    private const ENVIRONMENTS = ['test', 'prod'];

    /** The column separator asked of the CSV export — `;` is what the web app asks for. */
    private const SEPARATOR = ';';

    /** Exports in flight at once — kept small: it is a person's session on the vendor's API. */
    private const CONCURRENCY = 5;

    public function __construct(private readonly MonitorSession $session) {}

    /** The most the route returns per page; asking for more is silently capped. */
    public const MAX_PAGE = 100;

    /**
     * Completed executions in a window, newest first — the Executions tab.
     *
     * The filters are the tab's own, as captured on 2026-11-04: a pipeline
     * name (sent with `searchType: EXACT`, the only type seen) and a free-text
     * search over the execution payloads. Both are applied by the platform, so
     * a page of 100 is 100 matches, not 100 executions filtered down here.
     *
     * A page answers `pagination.nextCursor`; passing it back asks for the
     * page after it, in the same window and with the same filters.
     *
     * @return array<mixed>
     */
    public function executions(
        string $environment,
        CarbonInterface $from,
        CarbonInterface $to,
        int $limit = 50,
        ?string $pipeline = null,
        ?string $payloadSearch = null,
        ?string $cursor = null,
    ): array {
        return $this->windowed('/monitor/api/v1/executions', $environment, $from, $to, $limit, $pipeline, ['payloadSearch' => $payloadSearch], $cursor);
    }

    /**
     * Log lines in a window, newest first — the Pipeline Logs tab: every line
     * any execution logged, searched by its text across pipelines.
     *
     * Same request as the Executions tab (captured 2026-11-04), with the free
     * text under `logMessage` instead of `payloadSearch`.
     *
     * @return array<mixed>
     */
    public function pipelineLogs(
        string $environment,
        CarbonInterface $from,
        CarbonInterface $to,
        int $limit = 50,
        ?string $pipeline = null,
        ?string $message = null,
        ?string $cursor = null,
    ): array {
        return $this->windowed('/monitor/api/v1/pipeline-logs', $environment, $from, $to, $limit, $pipeline, ['logMessage' => $message], $cursor);
    }

    /**
     * The body both tabs send: a window, optional filters, a page.
     *
     * @param  array<string, string|null>  $search
     * @return array<mixed>
     */
    private function windowed(
        string $route,
        string $environment,
        CarbonInterface $from,
        CarbonInterface $to,
        int $limit,
        ?string $pipeline,
        array $search,
        ?string $cursor,
    ): array {
        $pipeline = trim((string) $pipeline);

        $filters = array_filter([
            'pipelineName' => $pipeline,
            'searchType'   => $pipeline !== '' ? 'EXACT' : '',
            ...array_map(fn (?string $value) => trim((string) $value), $search),
        ], fn (string $value) => $value !== '');

        $pagination = ['limit' => max(1, min($limit, self::MAX_PAGE)), 'order' => 'descending'];

        if ($cursor !== null) {
            $pagination['cursor'] = $cursor;
        }

        $response = $this->call('POST', $route, ['environment' => $this->environment($environment)], [
            'realm'       => $this->realm(),
            'environment' => $environment,
            'filters'     => (object) $filters,
            'timeRange'   => [
                'from' => $from->toImmutable()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'to'   => $to->toImmutable()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            ],
            'pagination' => $pagination,
        ]);

        $data = $response->json();

        if (! is_array($data)) {
            throw DigibeeApiException::nonJsonBody('POST', $route, $response->status());
        }

        return $data;
    }

    /**
     * Every log line of one execution, as the "export" button downloads them:
     * a CSV whose first row names the columns. Returned as rows keyed by those
     * names, so a renamed column shows up as a missing key instead of as data
     * silently read from the wrong position.
     *
     * @return list<array<string, string>>
     */
    public function executionLogs(string $environment, string $pipelineKey): array
    {
        $response = $this->call('GET', '/runtime/realms/{realm}/logs/export', [
            'environment' => $this->environment($environment),
            'pipelineKey' => $pipelineKey,
            'separator'   => self::SEPARATOR,
        ]);

        return $this->parseCsv($response->body());
    }

    /**
     * The logs of many executions, `CONCURRENCY` at a time — what a report
     * over a few hundred matches needs, at ~0.5 s an export one by one.
     *
     * The pool does not retry, so whatever did not come back as a success
     * (a connection hiccup, a 5xx) is asked again through `executionLogs()`,
     * which does — and which raises the same errors a single read would.
     *
     * @param  list<string>  $pipelineKeys
     * @return array<string, list<array<string, string>>|null> keyed by execution key; null = not readable
     */
    public function executionLogsMany(string $environment, array $pipelineKeys): array
    {
        $route = '/runtime/realms/{realm}/logs/export';
        $path = $this->path('GET', $route);
        $this->environment($environment);

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (string $key) => $this->configure($pool->as($key))->get($path, [
                'environment' => $environment,
                'pipelineKey' => $key,
                'separator'   => self::SEPARATOR,
            ]),
            $pipelineKeys,
        ), concurrency: self::CONCURRENCY);

        $logs = [];

        foreach ($pipelineKeys as $key) {
            $response = $responses[$key] ?? null;

            if ($response instanceof Response && $response->status() === 401) {
                throw DigibeeApiException::monitorSessionMissing();
            }

            if ($response instanceof Response && $response->successful()) {
                $logs[$key] = $this->parseCsv($response->body());

                continue;
            }

            // One execution the platform will not export must not cost the
            // other few hundred: it comes back as null, for the caller to
            // report, instead of ending the whole read.
            // An expired session is the exception: every other key would fail
            // the same way, so it ends the read with the login instruction.
            try {
                $logs[$key] = $this->executionLogs($environment, $key);
            } catch (DigibeeApiException $e) {
                if ($e->getMessage() === DigibeeApiException::monitorSessionMissing()->getMessage()) {
                    throw $e;
                }

                $logs[$key] = null;
            } catch (ConnectionException) {
                $logs[$key] = null;
            }
        }

        return $logs;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function call(string $method, string $route, array $query, ?array $body = null): Response
    {
        $path = $this->path($method, $route);

        $request = $this->configure(Http::createPendingRequest())
            // Every route here is a read, so a retry can never do a thing
            // twice — and WSL's resolver does time out now and then.
            // `throw: false` keeps the status checks below in charge.
            ->retry(2, 500, fn (\Throwable $e) => TransientHttpFailure::matches($e), throw: false)
            ->withQueryParameters($query);

        $response = $method === 'POST'
            ? $request->acceptJson()->asJson()->post($path, $body ?? [])
            : $request->get($path);

        if ($response->status() === 401) {
            throw DigibeeApiException::monitorSessionMissing();
        }

        if ($response->failed()) {
            throw DigibeeApiException::fromResponse($method, $path, $response);
        }

        return $response;
    }

    /** The one gate every request passes, single or pooled: a listed route, or nothing leaves. */
    private function path(string $method, string $route): string
    {
        if (! in_array("{$method} {$route}", self::ROUTES, true)) {
            throw DigibeeApiException::refusedMonitorRoute($method, $route);
        }

        return str_replace('{realm}', rawurlencode($this->realm()), $route);
    }

    private function configure(PendingRequest $request): PendingRequest
    {
        return $request->baseUrl((string) config('services.digibee.monitor.endpoint'))
            ->withHeaders($this->session->headers())
            ->timeout((int) config('services.digibee.monitor.timeout'))
            ->connectTimeout(5);
    }

    private function environment(string $environment): string
    {
        if (! in_array($environment, self::ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException("Unknown Digibee environment \"{$environment}\" — use test or prod.");
        }

        return $environment;
    }

    private function realm(): string
    {
        return (string) config('services.digibee.monitor.realm');
    }

    /** @return list<array<string, string>> */
    private function parseCsv(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, (string) $csv);
        rewind($handle);

        $header = null;
        $rows = [];

        while (($fields = fgetcsv($handle, null, self::SEPARATOR, '"', '')) !== false) {
            if ($fields === [null]) {
                continue;
            }

            if ($header === null) {
                $header = array_map('trim', $fields);

                continue;
            }

            // Every line ends in a separator, which reads as one more column
            // with an empty name — dropped rather than reported as data.
            $rows[] = array_filter(
                array_combine($header, array_pad(array_slice($fields, 0, count($header)), count($header), '')),
                fn (string $column) => $column !== '',
                ARRAY_FILTER_USE_KEY,
            );
        }

        fclose($handle);

        return $rows;
    }
}
