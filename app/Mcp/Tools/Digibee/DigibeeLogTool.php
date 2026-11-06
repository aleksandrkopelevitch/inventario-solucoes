<?php

namespace App\Mcp\Tools\Digibee;

use App\Enums\AccessModule;
use App\Mcp\Actor;
use App\Mcp\ReachesOutside;
use App\Mcp\Tool;
use App\Mcp\ToolResult;
use App\Models\DigibeeLogLookup;
use App\Support\Digibee\Monitor\LogRedactor;
use App\Support\Digibee\Monitor\Mcp\LogsTools;
use App\Support\Digibee\Monitor\ServerReportStore;
use App\Support\Digibee\Monitor\ServerSession;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The four Digibee log tools the local `digibee:mcp` offers, served by the
 * iSol `/mcp` too — the SAME `LogsTools`, given the server's session (the one
 * an admin sent, "Sessão Digibee"), reports kept as `DigibeeLogReport` rows,
 * and a search bounded by time because it answers inside an HTTP request.
 *
 * Integrations module, like the "Logs Digibee" screen. Three things the server
 * adds, because every read reaches Digibee as one person's account:
 *
 * - each call is recorded with who made it (`DigibeeLogLookup`);
 * - calls are rate-limited per caller (`PER_MINUTE`);
 * - the session's renewal is an admin's job, so a missing one says so and
 *   e-mails them (`ServerSession`), instead of sending the model to a terminal.
 */
abstract class DigibeeLogTool implements ReachesOutside, Tool
{
    /** Seconds a search spends opening executions before answering. */
    public const SEARCH_SECONDS = 30;

    /** Calls per caller per minute, across the four tools. */
    public const PER_MINUTE = 20;

    public function __construct(private readonly LogRedactor $redactor) {}

    /** What `DigibeeLogLookup::action` calls this tool. */
    abstract protected function action(): string;

    public function module(): ?AccessModule
    {
        return AccessModule::Integrations;
    }

    public function title(): string
    {
        return $this->spec()['title'];
    }

    public function description(): string
    {
        return $this->spec()['description'];
    }

    public function inputSchema(): array
    {
        return $this->spec()['inputSchema'];
    }

    public function handle(array $arguments, Actor $actor): ToolResult
    {
        if (! RateLimiter::attempt('digibee-logs:' . $actor->rateLimitKey(), self::PER_MINUTE, fn () => true)) {
            return ToolResult::failure('Muitas consultas aos logs da Digibee em pouco tempo. Espere um minuto antes de tentar de novo.');
        }

        DigibeeLogLookup::recordFor($actor, $this->action(), array_filter([
            'environment'   => is_string($arguments['environment'] ?? null) ? $arguments['environment'] : 'prod',
            'pipeline'      => is_string($arguments['pipeline'] ?? null) ? $arguments['pipeline'] : null,
            'term'          => is_string($arguments['term'] ?? null) ? $arguments['term'] : (is_string($arguments['text'] ?? null) ? $arguments['text'] : null),
            'execution_key' => is_string($arguments['key'] ?? null) ? $arguments['key'] : null,
        ]));

        $this->allowTime(self::SEARCH_SECONDS + 60);

        return $this->tools(new ServerReportStore($actor))->call($this->name(), $arguments);
    }

    /**
     * A search answers inside this request, so it may need more than PHP-FPM's
     * default 30 s. Only ever RAISES a limit: where there is none (the CLI, a
     * queue worker, the test suite), `set_time_limit()` would impose one on
     * the whole process — which is how it first killed the suite at 90 s.
     */
    private function allowTime(int $seconds): void
    {
        $current = (int) ini_get('max_execution_time');

        if ($current !== 0 && $current < $seconds) {
            set_time_limit($seconds);
        }
    }

    /** @return array{title: string, description: string, inputSchema: array<string, mixed>} */
    private function spec(): array
    {
        foreach ($this->tools(new ServerReportStore)->describe() as $tool) {
            if ($tool['name'] === $this->name()) {
                return $tool;
            }
        }

        throw new \LogicException("LogsTools describes no tool named {$this->name()}.");
    }

    private function tools(ServerReportStore $reports): LogsTools
    {
        return new LogsTools($this->redactor, new ServerSession, $reports, self::SEARCH_SECONDS);
    }
}
