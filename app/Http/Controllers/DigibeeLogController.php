<?php

namespace App\Http\Controllers;

use App\Enums\DigibeeLogReportStatus;
use App\Http\Requests\SearchDigibeeLogsRequest;
use App\Jobs\CollectDigibeeLogReport;
use App\Models\DigibeeLogLookup;
use App\Models\DigibeeLogReport;
use App\Support\Digibee\Monitor\DigibeeMonitorClient;
use App\Support\Digibee\Monitor\LogQuery;
use App\Support\Digibee\Monitor\LogRedactor;
use App\Support\Digibee\Monitor\LogSearch;
use App\Support\Digibee\Monitor\ServerSession;
use App\View\Components\Digibee\LogResults;
use App\View\Components\Digibee\ReportBody;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Logs Digibee": the Digibee execution logs read from iSol with the session
 * an admin sent ("Sessão Digibee"), for whoever reads the Integrations module.
 * The same reads as the local `digibee:logs` and the `/mcp` tools
 * (`LogSearch`), redacted before anything reaches the screen.
 *
 * "Buscar" lists matches without opening them (one page per source, inside
 * the request); "Gerar relatório" opens every match in a queued job.
 */
class DigibeeLogController extends Controller
{
    /** Matches listed by "Buscar" per source — one page of the platform's. */
    private const LIST_LIMIT = 100;

    public function __construct(private readonly LogRedactor $redactor) {}

    public function index(): View
    {
        $this->authorize('viewAny', DigibeeLogReport::class);

        return view('digibee-logs.index', [
            'available' => ServerSession::available(),
            'reports'   => DigibeeLogReport::query()->with(['user:id,name', 'mcpToken:id,name'])->latest('id')->limit(20)->get(),
        ]);
    }

    public function search(SearchDigibeeLogsRequest $request): JsonResponse
    {
        $query = $request->logQuery();
        $search = $this->searchFor($query);

        if ($search === null) {
            return $this->sessionGone();
        }

        DigibeeLogLookup::record('screen', 'list', $this->what($query), $request->user());

        ['coverage' => $coverage, 'found' => $found] = $search->find($query, pages: 1, limit: self::LIST_LIMIT);

        return response()->json(['updatableSlots' => [LogResults::slot($query, $coverage, $found)]]);
    }

    /** One execution's log, for the side panel. */
    public function execution(Request $request, string $key): JsonResponse
    {
        $this->authorize('viewAny', DigibeeLogReport::class);

        $environment = in_array($request->query('environment'), LogQuery::ENVIRONMENTS, true) ? $request->query('environment') : 'prod';
        $session = (new ServerSession)->session();

        if ($session === null) {
            return $this->sessionGone();
        }

        DigibeeLogLookup::record('screen', 'execution', ['environment' => $environment, 'execution_key' => $key], $request->user());

        $execution = (new LogSearch(new DigibeeMonitorClient($session)))->execution($environment, $key);

        return response()->json([
            'content' => view('digibee-logs.panels.execution', [
                'key'         => $key,
                'environment' => $environment,
                'execution'   => $execution === null ? null : json_decode($this->redactor->json($execution), true),
            ])->render(),
        ]);
    }

    public function storeReport(SearchDigibeeLogsRequest $request): JsonResponse
    {
        $query = $request->logQuery();

        if (! ServerSession::available()) {
            (new ServerSession)->session();

            return $this->sessionGone();
        }

        // Two workers serve every queued job in this app; one report at a time
        // keeps the Especialista's replies from waiting behind log exports.
        if (DigibeeLogReport::query()->inFlight()->exists()) {
            return response()->json([
                'type'    => 'warning',
                'message' => 'Já há um relatório sendo coletado. Espere ele terminar (aparece na lista abaixo) e tente de novo.',
            ]);
        }

        $report = DigibeeLogReport::begin($query, 'screen', DigibeeLogReportStatus::Pending, $request->user());
        DigibeeLogLookup::record('screen', 'report', $this->what($query), $request->user());
        CollectDigibeeLogReport::dispatch($report);

        return response()->json(['redirect' => route('digibee-logs.reports.show', $report)]);
    }

    public function show(DigibeeLogReport $report): View
    {
        $this->authorize('view', $report);

        return view('digibee-logs.report', ['report' => $report->load(['user:id,name', 'mcpToken:id,name'])]);
    }

    /** Polled while the report is collected; answers the body once it settled. */
    public function status(DigibeeLogReport $report): JsonResponse
    {
        $this->authorize('view', $report);

        return response()->json($report->status->settled() ? ['updatableSlots' => [ReportBody::slot($report)]] : []);
    }

    public function download(DigibeeLogReport $report): StreamedResponse
    {
        $this->authorize('view', $report);

        abort_unless($report->status === DigibeeLogReportStatus::Done && Storage::disk('local')->exists($report->file()), 404);

        return Storage::disk('local')->download($report->file(), 'digibee-logs-' . $report->uuid . '.json', [
            'Content-Type' => 'application/json',
        ]);
    }

    private function searchFor(LogQuery $query): ?LogSearch
    {
        $session = (new ServerSession)->session();

        return $session === null ? null : new LogSearch(new DigibeeMonitorClient($session));
    }

    private function sessionGone(): JsonResponse
    {
        return response()->json([
            'type'    => 'warning',
            'title'   => 'Sessão da Digibee expirada',
            'message' => 'A sessão que o iSol usa para ler a Digibee expirou. Os administradores já foram avisados para renová-la.',
        ], 409);
    }

    /** @return array<string, ?string> */
    private function what(LogQuery $query): array
    {
        return ['environment' => $query->environment, 'pipeline' => $query->pipeline, 'term' => $query->term];
    }
}
