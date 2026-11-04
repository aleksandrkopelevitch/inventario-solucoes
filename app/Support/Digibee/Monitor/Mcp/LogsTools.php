<?php

namespace App\Support\Digibee\Monitor\Mcp;

use App\Console\Commands\DigibeeLogsCommand;
use App\Exceptions\DigibeeApiException;
use App\Mcp\InvalidToolArguments;
use App\Mcp\Support\Arguments;
use App\Mcp\ToolResult;
use App\Support\Digibee\Monitor\DigibeeMonitorClient;
use App\Support\Digibee\Monitor\ExecutionCollector;
use App\Support\Digibee\Monitor\LogRedactor;
use App\Support\Digibee\Monitor\MonitorSession;
use App\Support\Digibee\Monitor\MonitorTime;
use App\Support\Digibee\Monitor\ReportDigest;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;

/**
 * The four tools the local Digibee logs MCP server offers a chat client.
 *
 * Read-only by construction, not by promise: they reach Digibee only through
 * `DigibeeMonitorClient`, whose closed route list is reads; the one thing they
 * write is a report file on this workstation's private disk.
 *
 * Every byte they answer passes through `LogRedactor::json()`. The answer goes
 * into a chat transcript — the same reason the terminal output is redacted,
 * with a wider audience.
 *
 * Descriptions and messages are in Portuguese: they are read by the model in
 * a PT-BR conversation and shown in the client's tool list, like the iSol
 * server's.
 */
final class LogsTools
{
    /** The default ceiling of one search — ~45 s at 5 exports in flight, inside a chat client's patience. */
    private const DEFAULT_OPEN = 200;

    /** The hard ceiling of one search, the same as `--collect`. */
    private const MAX_OPEN = 1000;

    /** Executions one report query returns. */
    private const QUERY_MAX = 50;

    /** Characters of one execution's log answered by get_digibee_execution. */
    private const EXECUTION_MAX_CHARS = 120_000;

    public function __construct(private readonly LogRedactor $redactor) {}

    /** @return list<array<string, mixed>> */
    public function describe(): array
    {
        $filters = [
            'pipeline'    => ['type' => 'string', 'description' => 'Nome EXATO da pipeline (ex.: evt-send-estoque-vtex). Opcional.'],
            'term'        => ['type' => 'string', 'description' => 'Texto a procurar: um pedido, um SKU, um CNPJ, uma mensagem de erro. Opcional.'],
            'where'       => ['type' => 'string', 'enum' => ['both', 'lines', 'payloads'], 'description' => 'Onde procurar o termo: nas linhas de log (lines), nos payloads de request/response (payloads) ou nos dois (both, padrão). Ignorado sem term.'],
            'since'       => ['type' => 'string', 'description' => 'Início: quanto tempo atrás ("30m", "6h", "2d") ou data/hora local ("03/11/2026 14:00", "03/11 14:00", "2026-11-03", "14:00"). Padrão "1d". Os logs vivem 10 dias em prod e 3 em test.'],
            'until'       => ['type' => 'string', 'description' => 'Fim, nos mesmos formatos. Padrão: agora. Uma data sem hora é a meia-noite: o dia 03/11 inteiro é since "03/11" e until "04/11".'],
            'environment' => ['type' => 'string', 'enum' => ['prod', 'test'], 'description' => 'Padrão prod.'],
        ];

        return [
            $this->tool(
                'count_digibee_executions',
                'Contar execuções Digibee',
                <<<'TXT'
                Conta quantas execuções (e linhas de log) batem com os filtros, SEM abri-las. Rápido. Use antes
                de search_digibee_logs quando o pedido for amplo (uma pipeline sem termo, ou dias inteiros):
                uma pipeline movimentada roda milhares de execuções por hora, em rajadas. "10000+" significa
                "pelo menos 10000" — a plataforma não conta além disso.
                TXT,
                $filters,
            ),
            $this->tool(
                'search_digibee_logs',
                'Buscar e coletar logs Digibee',
                <<<'TXT'
                Busca execuções de pipelines Digibee por termo, pipeline e período, ABRE TODAS as execuções
                encontradas (o log inteiro de cada uma) e devolve um resumo agregado: execuções por pipeline e
                versão, status, duração (mín/mediana/p95/máx), mensagens de ERROR/WARN agrupadas com contagem,
                chamadas HTTP por destino e status, e uma linha do tempo com as linhas relevantes de cada execução.
                O relatório completo fica salvo nesta máquina; o resultado traz o "report" para consultar com
                query_digibee_report. Abre ~5 execuções por segundo; acima de max_executions, as demais são
                listadas e não abertas. Sem term, coleta todas as execuções da pipeline no período.
                TXT,
                $filters + [
                    'max_executions' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_OPEN, 'description' => 'Quantas execuções abrir no máximo. Padrão ' . self::DEFAULT_OPEN . ' (~45 s); até ' . self::MAX_OPEN . ' (~3,5 min).'],
                ],
            ),
            $this->tool(
                'query_digibee_report',
                'Consultar relatório coletado',
                <<<'TXT'
                Consulta um relatório salvo por search_digibee_logs, sem chamar a Digibee de novo. Filtra as
                execuções por chave, nível de log, status ou texto (no log e nos payloads) e devolve até 50 por
                vez (use offset para paginar). Com key e full=true, devolve o log inteiro daquela execução.
                TXT,
                [
                    'report' => ['type' => 'string', 'description' => 'O "report" devolvido por search_digibee_logs.'],
                    'key'    => ['type' => 'string', 'description' => 'Chave de uma execução.'],
                    'level'  => ['type' => 'string', 'enum' => ['ERROR', 'WARN', 'INFO'], 'description' => 'Só execuções com ao menos uma linha neste nível.'],
                    'status' => ['type' => 'integer', 'description' => 'Só execuções com este status de resposta.'],
                    'text'   => ['type' => 'string', 'description' => 'Só execuções cujo log ou payload contém este texto.'],
                    'full'   => ['type' => 'boolean', 'description' => 'Linhas completas em vez do resumo de cada execução.'],
                    'offset' => ['type' => 'integer', 'minimum' => 0],
                    'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::QUERY_MAX],
                ],
                ['report'],
            ),
            $this->tool(
                'get_digibee_execution',
                'Abrir uma execução Digibee',
                'Todas as linhas de log de UMA execução, em ordem, direto da Digibee — para uma chave que não está num relatório coletado.',
                [
                    'key'         => ['type' => 'string', 'description' => 'Chave da execução (pipeline key).'],
                    'environment' => $filters['environment'],
                ],
                ['key'],
            ),
        ];
    }

    /** @param  array<string, mixed>  $arguments */
    public function call(string $name, array $arguments): ToolResult
    {
        $args = new Arguments($arguments);

        try {
            return match ($name) {
                'count_digibee_executions' => $this->count($args),
                'search_digibee_logs'      => $this->search($args),
                'query_digibee_report'     => $this->query($args),
                'get_digibee_execution'    => $this->execution($args),
                default                    => throw new InvalidToolArguments("Ferramenta \"{$name}\" não existe."),
            };
        } catch (MissingSession) {
            return ToolResult::failure('A sessão da Digibee não existe ou expirou (dura ~24h). ' . self::LOGIN_HOWTO);
        } catch (\InvalidArgumentException $e) {
            if ($e instanceof InvalidToolArguments) {
                throw $e;
            }

            return ToolResult::failure($e->getMessage());
        } catch (DigibeeApiException|ConnectionException $e) {
            // Every failure to reach Digibee ends in the same instruction, by
            // decision: a session that died mid-day answers 401, sometimes 403,
            // sometimes a dropped connection — and signing in again is cheap,
            // while a model guessing which of those it was is not.
            return ToolResult::failure('Não consegui falar com a Digibee: ' . $this->redactor->redact($e->getMessage()) . ' ' . self::LOGIN_HOWTO);
        }
    }

    /** What to tell the person whenever Digibee cannot be reached — only they can sign in. */
    public const LOGIN_HOWTO = 'Oriente o usuário a fazer o login na Digibee: abrir o terminal do Ubuntu (WSL), rodar '
        . '`cd ~/inventario-solucoes && php artisan digibee:monitor:login` e entrar pelo IdP na janela que abrir — '
        . 'só uma pessoa pode fazer isso. Depois, repita a chamada; não é preciso reiniciar o app nem o servidor.';

    private function count(Arguments $args): ToolResult
    {
        [$client, $environment, $from, $to] = $this->context($args);
        $pipeline = $args->string('pipeline');
        $term = $args->string('term');

        $answer = ['window' => $this->windowOf($from, $to), 'environment' => $environment];

        foreach ($this->sources($args) as $source => $inLines) {
            $data = $inLines
                ? $client->pipelineLogs($environment, $from, $to, 1, $pipeline, $term)
                : $client->executions($environment, $from, $to, 1, $pipeline, $term);

            $answer[$source] = $this->countOf($data['total'] ?? null);
        }

        return $this->json($answer);
    }

    private function search(Arguments $args): ToolResult
    {
        [$client, $environment, $from, $to] = $this->context($args);
        $pipeline = $args->string('pipeline');
        $term = $args->string('term');
        $max = $args->int('max_executions', self::DEFAULT_OPEN, 1, self::MAX_OPEN);

        if ($pipeline === null && $term === null) {
            throw new \InvalidArgumentException('Informe ao menos uma pipeline ou um termo: sem nenhum dos dois, a busca é o realm inteiro, milhares de execuções por minuto.');
        }

        $collector = new ExecutionCollector($client);
        $coverage = [];
        $found = [];

        foreach ($this->sources($args) as $source => $inLines) {
            $search = $collector->find($environment, $from, $to, $pipeline, $term, $inLines);

            $coverage[$source] = [
                'matches'        => $search['matches'],
                'platform_total' => $this->countOf($search['total']),
                'more_in_window' => $search['has_next'],
                'executions'     => count($search['found']),
            ];

            // The same execution found through both doors is one execution:
            // payload fields from one, matched lines from the other.
            foreach ($search['found'] as $key => $execution) {
                $found[$key] = isset($found[$key]) ? $found[$key] + $execution : $execution;
            }
        }

        $opened = $collector->open($environment, $found, $max);

        $report = [
            'query' => array_filter([
                'environment' => $environment,
                'searched'    => implode(' + ', array_keys($coverage)),
                'pipeline'    => $pipeline,
                'term'        => $term,
                'from'        => MonitorTime::iso($from->toIso8601String()),
                'to'          => MonitorTime::iso($to->toIso8601String()),
                'timezone'    => MonitorTime::timezone(),
            ]),
            'coverage'         => $coverage,
            'executions_found' => count($found),
            'not_opened'       => $opened['not_opened'],
            'unreadable'       => $opened['unreadable'],
            'executions'       => $opened['executions'],
        ];

        $name = $this->reportName($pipeline, $term);
        $path = DigibeeLogsCommand::reportPath($name);
        file_put_contents($path, $this->redactor->json($report));
        chmod($path, 0600);

        return $this->json([
            'report'   => $name,
            'query'    => $report['query'],
            'coverage' => $coverage + array_filter([
                'executions_found'  => count($found),
                'executions_opened' => count($opened['executions']),
                'not_opened'        => count($opened['not_opened']) ?: null,
                'not_opened_note'   => $opened['not_opened'] !== []
                    ? 'Passou de max_executions: as execuções mais antigas do período não foram abertas. Divida o período ou aumente max_executions.'
                    : null,
                'unreadable' => count($opened['unreadable']) ?: null,
            ]),
            'note' => ($coverage['payloads']['more_in_window'] ?? false) || ($coverage['lines']['more_in_window'] ?? false)
                ? 'Há mais resultados no período do que uma busca alcança (2000 por fonte). Divida o período em intervalos menores.'
                : null,
        ] + ReportDigest::of($opened['executions'], $term));
    }

    private function query(Arguments $args): ToolResult
    {
        $report = $this->readReport($args->required('report'));
        $key = $args->string('key');
        $level = $args->string('level');
        $text = $args->string('text');
        $status = $args->string('status');
        $full = $args->bool('full');
        $offset = $args->int('offset', 0, 0, PHP_INT_MAX);
        $limit = $args->int('limit', 20, 1, self::QUERY_MAX);

        $matching = array_values(array_filter($report['executions'] ?? [], function (array $execution) use ($key, $level, $text, $status) {
            if ($key !== null && ($execution['key'] ?? null) !== $key) {
                return false;
            }

            if ($status !== null && (string) ($execution['status'] ?? '') !== $status) {
                return false;
            }

            if ($level !== null && ! array_filter(array_keys($execution['levels'] ?? []), fn ($l) => str_starts_with(strtoupper((string) $l), strtoupper($level)))) {
                return false;
            }

            if ($text !== null) {
                $haystack = implode("\n", array_column($execution['lines'] ?? [], 'message'))
                    . "\n" . ($execution['payload_request'] ?? '') . "\n" . ($execution['payload_response'] ?? '');

                return mb_stripos($haystack, $text) !== false;
            }

            return true;
        }));

        $page = array_slice($matching, $offset, $limit);

        return $this->json([
            'report'     => $args->required('report'),
            'matching'   => count($matching),
            'offset'     => $offset,
            'returned'   => count($page),
            'more'       => $offset + count($page) < count($matching),
            'executions' => $full ? $page : array_map(fn (array $execution) => ReportDigest::compact($execution, $text), $page),
        ]);
    }

    private function execution(Arguments $args): ToolResult
    {
        $environment = $this->environment($args);
        $key = $args->required('key');
        $rows = array_reverse($this->client()->executionLogs($environment, $key));

        if ($rows === []) {
            return ToolResult::failure("Nenhuma linha de log para a execução {$key} em {$environment} — a chave está errada ou o log já expirou (10 dias em prod, 3 em test).");
        }

        $lines = array_map(fn (array $row) => [
            'at'      => MonitorTime::iso($row['timestamp'] ?? null),
            'level'   => $row['logLevel'] ?? null,
            'message' => $row['logMessage'] ?? null,
        ], $rows);

        $body = $this->redactor->json(['key' => $key, 'pipeline' => $rows[0]['pipelineName'] ?? null, 'environment' => $environment, 'lines' => $lines]);

        if (mb_strlen($body) > self::EXECUTION_MAX_CHARS) {
            $body = mb_substr($body, 0, self::EXECUTION_MAX_CHARS) . "\n… (cortado: o log desta execução passa de " . self::EXECUTION_MAX_CHARS . ' caracteres)';
        }

        return ToolResult::text($body);
    }

    /**
     * The session, the client and the window, read before any request — a
     * malformed `since` is refused before Digibee hears a thing.
     *
     * @return array{0: DigibeeMonitorClient, 1: string, 2: CarbonImmutable, 3: CarbonImmutable}
     */
    private function context(Arguments $args): array
    {
        $environment = $this->environment($args);
        [$from, $to] = MonitorTime::window($args->string('since', '1d'), $args->string('until'));

        return [$this->client(), $environment, $from, $to];
    }

    /**
     * Read from disk on every call: a person signing in again mid-conversation
     * is picked up by the next call, with nothing to restart.
     */
    private function client(): DigibeeMonitorClient
    {
        $session = MonitorSession::fromFile((string) config('services.digibee.monitor.session_path'));

        if ($session === null || $session->expired()) {
            throw new MissingSession;
        }

        return new DigibeeMonitorClient($session);
    }

    private function environment(Arguments $args): string
    {
        $environment = $args->string('environment', 'prod');

        if (! in_array($environment, ['prod', 'test'], true)) {
            throw new InvalidToolArguments('environment deve ser "prod" ou "test".');
        }

        return $environment;
    }

    /**
     * Which routes to ask: with a term, where it may appear; without one,
     * the executions of the window.
     *
     * @return array<string, bool> source name => searches log lines
     */
    private function sources(Arguments $args): array
    {
        if ($args->string('term') === null) {
            return ['executions' => false];
        }

        return match ($args->string('where', 'both')) {
            'lines'    => ['lines' => true],
            'payloads' => ['payloads' => false],
            default    => ['lines' => true, 'payloads' => false],
        };
    }

    private function countOf(mixed $total): int|string|null
    {
        if (! is_int($total)) {
            return null;
        }

        return $total >= ExecutionCollector::CAPPED_TOTAL ? ExecutionCollector::CAPPED_TOTAL . '+' : $total;
    }

    /** @return array<string, string> */
    private function windowOf(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ['from' => MonitorTime::display($from), 'to' => MonitorTime::display($to), 'timezone' => MonitorTime::timezone()];
    }

    /**
     * Named after the pipeline and a HASH of the term: the term may be a CPF,
     * and a file name is read by more things than its contents are.
     */
    private function reportName(?string $pipeline, ?string $term): string
    {
        $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower($pipeline ?? 'todas')) ?: 'todas';

        return 'mcp-' . now()->format('Ymd-His') . '-' . trim(substr($slug, 0, 40), '-')
            . ($term !== null ? '-' . substr(sha1($term), 0, 8) : '') . '.json';
    }

    /**
     * A report by its bare file name, from the reports directory only — the
     * name comes from a chat, and a path in it would read any file this user can.
     *
     * @return array<string, mixed>
     */
    private function readReport(string $name): array
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.json$/', $name) !== 1 || str_contains($name, '..')) {
            throw new InvalidToolArguments('report deve ser o nome devolvido por search_digibee_logs (ex.: mcp-20261104-101500-evt-send-estoque-vtex.json).');
        }

        $path = DigibeeLogsCommand::reportPath($name);

        if (! is_file($path)) {
            throw new \InvalidArgumentException("Relatório \"{$name}\" não encontrado nesta máquina.");
        }

        return (array) json_decode((string) file_get_contents($path), true);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function tool(string $name, string $title, string $description, array $properties, array $required = []): array
    {
        return [
            'name'        => $name,
            'title'       => $title,
            'description' => $description,
            'inputSchema' => array_filter([
                'type'       => 'object',
                'properties' => $properties,
                'required'   => $required ?: null,
            ]),
            'annotations' => ['readOnlyHint' => true, 'openWorldHint' => true],
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function json(array $data): ToolResult
    {
        return ToolResult::text($this->redactor->json(array_filter($data, fn ($value) => $value !== null)));
    }
}
