<?php

namespace App\Support\Digibee\Monitor\Mcp;

use App\Mcp\InvalidToolArguments;
use App\Mcp\JsonRpc;
use App\Mcp\McpServer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A local MCP server over stdio: Digibee execution logs for a chat client on
 * this workstation (Claude Desktop, launched through `wsl.exe`).
 *
 * Why not a tool on the iSol server at /mcp: what it replays is a PERSON's
 * Monitor session — their whole role, production deletes included — and the
 * sign-in that refreshes it opens a browser for their IdP. Neither belongs on
 * a shared droplet behind a token. Here the session never leaves the machine
 * it was captured on, and the chat client is the person's own.
 *
 * The transport is stdio, which the iSol server's HTTP code does not cover:
 * one JSON-RPC message per line in, one per line out, and NOTHING else on
 * stdout — a stray warning there is a corrupt frame, and the client drops the
 * connection. The envelope helpers (`App\Mcp\JsonRpc`, `ToolResult`) are
 * shared.
 */
final class LogsMcpServer
{
    public function __construct(private readonly LogsTools $tools) {}

    /**
     * Reads messages until the client closes stdin.
     *
     * @param  resource  $in
     * @param  resource  $out
     */
    public function serve($in, $out): void
    {
        while (($line = fgets($in)) !== false) {
            if (trim($line) === '') {
                continue;
            }

            $message = json_decode($line, true);

            $response = is_array($message) && ! array_is_list($message)
                ? $this->handle($message)
                : JsonRpc::error(null, JsonRpc::PARSE_ERROR, 'Mensagem JSON-RPC inválida.');

            if ($response !== null) {
                fwrite($out, json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                fflush($out);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null null for a notification
     */
    public function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        $params = (array) ($message['params'] ?? []);

        // A notification (`notifications/initialized`, `…/cancelled`) carries
        // no id and is never answered.
        if (! array_key_exists('id', $message)) {
            return null;
        }

        if (! is_string($method) || $method === '') {
            return JsonRpc::error($id, JsonRpc::INVALID_REQUEST, 'Mensagem sem "method".');
        }

        try {
            return match ($method) {
                'initialize' => JsonRpc::result($id, $this->initialize($params)),
                'ping'       => JsonRpc::result($id, []),
                'tools/list' => JsonRpc::result($id, ['tools' => $this->tools->describe()]),
                'tools/call' => JsonRpc::result($id, $this->call($params)),
                default      => JsonRpc::error($id, JsonRpc::METHOD_NOT_FOUND, "Método \"{$method}\" não suportado."),
            };
        } catch (InvalidToolArguments $e) {
            return JsonRpc::error($id, JsonRpc::INVALID_PARAMS, $e->getMessage());
        } catch (Throwable $e) {
            // Logged in full, answered in outline: the message lands in a
            // chat, and a stack trace there helps nobody.
            Log::error('Digibee logs MCP call failed', ['method' => $method, 'exception' => $e]);

            return JsonRpc::error($id, JsonRpc::INTERNAL_ERROR, 'Erro interno no servidor de logs Digibee: ' . class_basename($e) . '. Detalhes no log do Laravel.');
        }
    }

    /** @param  array<string, mixed>  $params */
    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => in_array($requested, McpServer::SUPPORTED_PROTOCOLS, true) ? $requested : McpServer::DEFAULT_PROTOCOL,
            'capabilities'    => ['tools' => ['listChanged' => false]],
            'serverInfo'      => ['name' => 'digibee-logs', 'title' => 'Logs Digibee (Leo Madeiras)', 'version' => '1.0.0'],
            'instructions'    => self::INSTRUCTIONS,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function call(array $params): array
    {
        $name = $params['name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new InvalidToolArguments('Chamada sem o nome da ferramenta.');
        }

        return $this->tools->call($name, (array) ($params['arguments'] ?? []))->toArray();
    }

    /**
     * Handed to the model once, at connection: how a report is shaped and the
     * rules about masked data — what the `/digibee-logs` skill says to Claude
     * Code, said to a chat client.
     */
    private const INSTRUCTIONS = <<<'TXT'
    Logs de execução das pipelines Digibee da Leo Madeiras (prod e test), lidos com a sessão do
    próprio usuário, nesta máquina. Somente leitura.

    Como trabalhar:
    - O objetivo é TUDO o que bate com o pedido, não uma amostra. search_digibee_logs abre todas as
      execuções encontradas; para pedidos amplos (pipeline sem termo, dias inteiros), use antes
      count_digibee_executions e, se passar de ~1000, diga ao usuário o tamanho e proponha um termo
      ou um período menor. Para cobrir um período grande, divida em intervalos consecutivos.
    - Converta "ontem", "hoje", "dia 03/11" em datas (since/until). O dia inteiro de 03/11 é
      since "03/11" e until "04/11".
    - Para detalhar, use query_digibee_report sobre o relatório salvo, em vez de buscar de novo.

    Relatório, em português, no formato pedido; por padrão: Resumo (o que foi buscado, o que
    aconteceu, o ponto a olhar), Números (por pipeline/versão: execuções, com erro, status, duração,
    primeira e última), Erros e avisos (cada mensagem distinta com contagem, primeira/última vez e uma
    chave de exemplo), Linha do tempo (para um termo: cada execução em ordem; para um relatório amplo:
    os momentos notáveis) e Leitura (o que os dados sugerem, marcado como inferência, e a próxima
    checagem). Sempre diga a cobertura: o que foi coletado contra o total da plataforma, e o que ficou
    de fora ou ilegível.

    Dados mascarados ([redacted], [email], [cpf], [cnpj], [phone], [redacted-jwt]) continuam
    mascarados: nunca tente recuperá-los e, se um dado pessoal ou credencial aparecer sem máscara,
    aponte isso em vez de repeti-lo. A busca roda sobre o texto ORIGINAL, então uma execução pode bater
    sem mostrar o termo: ele estava num campo mascarado — diga isso, nomeando o campo.

    SEMPRE que uma ferramenta não conseguir falar com a Digibee — sessão ausente ou expirada, 401,
    403, erro de conexão ou timeout — pare e oriente o usuário a fazer o login: abrir o terminal do
    Ubuntu (WSL), rodar `cd ~/inventario-solucoes && php artisan digibee:monitor:login` e entrar pelo
    IdP na janela que abrir. Só ele pode fazer isso. Depois, repita a chamada.
    TXT;
}
