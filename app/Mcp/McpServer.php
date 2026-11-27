<?php

namespace App\Mcp;

use App\Enums\AccessModule;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches one JSON-RPC message. The transport (`McpController`) knows about
 * HTTP; this knows about MCP.
 *
 * The server is STATELESS, which is both what the 2026-07-28 revision requires
 * and what this app can actually offer: it runs behind a shared droplet with no
 * sticky sessions, so a session id handed out by one process would be
 * meaningless to the next request. Every message is therefore self-contained —
 * `tools/call` works whether or not `initialize` was ever sent, which is what
 * keeps a client that reconnects mid-conversation working.
 *
 * `initialize` is still answered, because every client from 2024-11-05 through
 * 2025-06-18 sends it and refuses to proceed without a reply.
 */
class McpServer
{
    /**
     * Protocol revisions this server answers to, newest first.
     *
     * All four are listed and the list is not aspirational: the wire format this
     * server actually speaks — a JSON-RPC request in a POST body, a JSON object
     * in the response — is the intersection of all of them. What changed across
     * the revisions (SSE streams, session ids, the handshake, batching) is
     * precisely what a stateless read-only server never uses.
     */
    public const SUPPORTED_PROTOCOLS = ['2026-07-28', '2025-06-18', '2025-03-26', '2024-11-05'];

    /**
     * What we answer when the client asks for a revision we do not know.
     *
     * Deliberately NOT the newest. The spec's rule for an unsupported request is
     * to answer with a version the server does support and let the client decide;
     * answering with the newest would hand a 2024-era client a revision that
     * removed the transport it is holding. This is the one every shipping client
     * understands.
     */
    public const DEFAULT_PROTOCOL = '2025-06-18';

    public function __construct(private readonly ToolRegistry $tools) {}

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null null for a notification — nothing to answer
     */
    public function handle(array $message, Actor $actor): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        $params = (array) ($message['params'] ?? []);

        if (! is_string($method) || $method === '') {
            return JsonRpc::error($id, JsonRpc::INVALID_REQUEST, 'Mensagem sem "method".');
        }

        // A notification carries no `id` and MUST NOT be answered. The only ones
        // a client sends here are `notifications/initialized` and
        // `notifications/cancelled`; both are acknowledged by the HTTP 202 the
        // controller returns, and there is nothing for a stateless server to do
        // with either.
        if (! array_key_exists('id', $message)) {
            return null;
        }

        try {
            return match ($method) {
                'initialize' => JsonRpc::result($id, $this->initialize($params, $actor)),
                'ping'       => JsonRpc::result($id, []),
                'tools/list' => JsonRpc::result($id, ['tools' => $this->tools->describe($actor)]),
                'tools/call' => JsonRpc::result($id, $this->call($params, $actor)),
                default      => JsonRpc::error($id, JsonRpc::METHOD_NOT_FOUND, "Método \"{$method}\" não suportado."),
            };
        } catch (InvalidToolArguments $e) {
            return JsonRpc::error($id, JsonRpc::INVALID_PARAMS, $e->getMessage());
        } catch (Throwable $e) {
            // Logged in full, answered in outline. The client is a chat app that
            // prints `error.message` into somebody's conversation, so a stack
            // trace there is both useless and a disclosure.
            Log::error('MCP tool failed', [
                'method'    => $method,
                'caller'    => $actor->label(),
                'exception' => $e,
            ]);

            return JsonRpc::error($id, JsonRpc::INTERNAL_ERROR, 'Erro interno ao executar a chamada MCP.');
        }
    }

    /** @param array<string, mixed> $params */
    private function initialize(array $params, Actor $actor): array
    {
        $requested = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => in_array($requested, self::SUPPORTED_PROTOCOLS, true)
                ? $requested
                : self::DEFAULT_PROTOCOL,
            // Tools only. No `resources` and no `prompts`: a resource is
            // addressed by URI and listed in full, which for 621 documentation
            // pages means a client downloading the corpus to decide what to
            // read. Search plus a getter is the same corpus, asked the way it is
            // actually used.
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo'   => [
                'name'    => 'inventario-solucoes',
                'title'   => 'Inventário de Soluções — Leo Madeiras',
                'version' => '1.0.0',
            ],
            'instructions' => $this->instructions($actor),
        ];
    }

    /**
     * Handed to the model once, at connection — so it says the things that are
     * true of every tool and would otherwise be repeated in twelve descriptions.
     *
     * Both halves earn their place. The catalog is PT-BR, and a model that
     * translates a category before searching finds nothing. And the
     * documentation half of this server sees published cadernos only, which is
     * the difference between "não está documentado" and "não foi publicado" —
     * two answers a person acts on very differently.
     *
     * A connection that cannot read some module is told so HERE, once: a model
     * that cannot see `search_solutions` does not conclude "não tenho acesso";
     * it concludes the catalog is empty, and says so to somebody.
     */
    private function instructions(Actor $actor): string
    {
        $closed = collect($this->tools->every())
            ->map(fn (Tool $tool) => $tool->module())
            ->filter()
            ->unique()
            ->reject(fn (AccessModule $module) => $actor->canRead($module))
            ->map(fn (AccessModule $module) => $module->label())
            ->values();

        $limit = $closed->isEmpty() ? '' : "\n\nEsta conexão NÃO alcança: " . $closed->implode('; ')
            . '. É o mesmo alcance que esta conta tem no app, não uma falha — não diga que esses dados não existem.';

        return <<<'TXT'
        Inventário de Soluções da Leo Madeiras: o catálogo de sistemas e integrações,
        os relacionamentos entre eles e a base de conhecimento interna.

        Os dados são em português. Busque pelos termos como estão cadastrados
        (categorias, status e diretorias são valores em PT-BR) e não traduza um termo
        antes de pesquisar.

        A documentação exposta aqui é apenas a dos cadernos PUBLICADOS na base de
        conhecimento interna. Um caderno que existe no app mas não foi publicado é
        invisível por aqui — ao relatar que algo não está documentado, diga que não foi
        encontrado NA BASE PUBLICADA, nunca que não existe documentação.
        TXT . "\n\n" . $this->access($actor) . $this->codeGuide($actor) . $limit;
    }

    /**
     * Pointed at once, here, because the moment it is needed — "documente
     * este repositório", "desenhe a sequência" — is a moment the model
     * otherwise improvises: Mermaid, which this app does not render, or a
     * `{% uml %}` body it never saw the shape of.
     */
    private function codeGuide(Actor $actor): string
    {
        return $actor->canRead(AccessModule::Documentation)
            ? "\n\nPara documentar código-fonte (pasta, zip ou repositório) num caderno, ou para escrever "
                . 'um diagrama UML ou uma cena animada numa página, chame get_code_documentation_guide ANTES: '
                . 'ele traz o roteiro e a sintaxe. O iSol não renderiza Mermaid.'
            : '';
    }

    /**
     * What this connection may change — said once, here, because it differs
     * per caller and a model that is not told assumes either everything or
     * nothing.
     *
     * Three cases. A connection that writes is told to confirm with the person
     * and to show them what a refused write would lose: the confirmation
     * arguments of the write tools are designed to be read by a HUMAN in the
     * client's approval prompt, and a model that retries them on its own
     * defeats the point (docs-and-specs/mcp-write-tools.md §2.3). An Editor
     * whose connection was approved read-only is told how to change that,
     * because otherwise it answers "não consigo editar" with no way forward.
     * Everybody else is read-only, and says so.
     */
    private function access(Actor $actor): string
    {
        $writable = collect($this->tools->writableModules())
            ->filter(fn (AccessModule $module) => $actor->canWrite($module))
            ->map(fn (AccessModule $module) => $module->label());

        if ($writable->isNotEmpty()) {
            return 'Esta conexão também pode CRIAR e EDITAR registros em: ' . $writable->implode('; ') . '. '
                . 'Cada alteração é feita em nome da pessoa conectada e fica registrada. Nada é apagado. '
                . 'Antes de criar, alterar ou desvincular algo, confirme com a pessoa. '
                . 'Quando uma ferramenta recusar por perda de conteúdo ou por duplicidade, mostre à pessoa '
                . 'o que seria perdido ou duplicado e só repita com a confirmação dela.'
                . ($actor->canWrite(AccessModule::Documentation)
                    ? ' Esta conexão também enxerga os cadernos NÃO publicados (marcados com "published": false): '
                        . 'não cite o texto deles como sendo da base publicada.'
                    : '');
        }

        if ($actor->couldWriteIfGranted()) {
            return 'Esta conexão é somente leitura. A conta conectada pode editar no app; para editar por aqui, '
                . 'reconecte o conector e autorize a edição na tela de consentimento.';
        }

        return 'Todo o acesso desta conexão é somente leitura.';
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function call(array $params, Actor $actor): array
    {
        $name = $params['name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new InvalidToolArguments('Chamada sem o nome da ferramenta.');
        }

        $tool = $this->tools->find($name, $actor);

        if (! $tool) {
            throw new InvalidToolArguments("Ferramenta \"{$name}\" não existe.");
        }

        return $tool->handle((array) ($params['arguments'] ?? []), $actor)->toArray();
    }
}
