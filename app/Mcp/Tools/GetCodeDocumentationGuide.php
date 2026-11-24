<?php

namespace App\Mcp\Tools;

use App\Enums\AccessModule;
use App\Mcp\Actor;
use App\Mcp\Tool;
use App\Mcp\ToolResult;

/**
 * The playbook for documenting a code base inside a caderno — and the one
 * place the dialect's two drawn constructs, `{% uml %}` and `{% scene %}`,
 * are taught to a model.
 *
 * It is a TOOL rather than a resource or a prompt for two reasons. The server
 * speaks tools only (McpServer::initialize explains why), and a tool is what
 * every client actually surfaces to the model; and the code it documents never
 * reaches this server at all — the CLIENT reads the folder, the zip or the
 * repository with its own tools, and this server only receives finished pages
 * through create_page/update_page. A server-side reader would need a GitHub
 * credential, a sandbox for untrusted code and a job longer than a request,
 * to do what the client already does.
 *
 * The text lives in `resources/data/mcp/code-documentation-guide.md`, so the
 * playbook is edited as a document, not as a PHP string. Asked with a caderno
 * or a solution, the answer carries their current state too, which is what
 * stops a run from writing pages a caderno already has.
 */
class GetCodeDocumentationGuide implements Tool
{
    public const GUIDE = 'data/mcp/code-documentation-guide.md';

    public function __construct(
        private readonly GetNotebook $notebook,
        private readonly GetSolution $solution,
    ) {}

    public function module(): ?AccessModule
    {
        return AccessModule::Documentation;
    }

    public function name(): string
    {
        return 'get_code_documentation_guide';
    }

    public function title(): string
    {
        return 'Roteiro para documentar código num caderno';
    }

    public function description(): string
    {
        return <<<'TXT'
        Chame ANTES de documentar código-fonte (uma pasta, um zip ou um repositório
        GitHub) num caderno, e antes de escrever qualquer diagrama UML ({% uml %}) ou cena
        animada ({% scene %}) numa página. Devolve o roteiro — como ler o código, o que
        nunca copiar (segredos, dados pessoais), quais páginas criar — e a sintaxe completa
        dos diagramas UML (sequência, classes, componentes, implantação, casos de uso,
        atividades, estados, pacotes) e das cenas.

        O iSol não lê o código: quem lê é você, com as ferramentas do seu ambiente; o iSol
        recebe as páginas prontas por create_page/update_page. Passe "notebook" e/ou
        "solution" para receber também a árvore atual do caderno e o registro da solução.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'notebook' => ['type' => 'string', 'description' => 'Slug do caderno que vai receber a documentação (opcional).'],
                'solution' => ['type' => 'string', 'description' => 'Slug da solução documentada (opcional, de search_solutions).'],
            ],
            'required' => [],
        ];
    }

    public function handle(array $arguments, Actor $actor): ToolResult
    {
        $text = (string) file_get_contents(resource_path(self::GUIDE));

        if (! $actor->canWrite(AccessModule::Documentation)) {
            $text = '> Esta conexão NÃO escreve na documentação: use o roteiro para planejar e '
                . 'entregar o texto à pessoa, ou peça a ela que reconecte com permissão de escrita '
                . "(Editor de Documentação).\n\n" . $text;
        }

        $context = [];

        if (is_string($slug = $arguments['notebook'] ?? null) && trim($slug) !== '') {
            $context[] = "### Caderno \"{$slug}\" hoje (get_notebook)\n\n" . $this->text($this->notebook->handle(['notebook' => trim($slug)], $actor));
        }

        if (is_string($slug = $arguments['solution'] ?? null) && trim($slug) !== '') {
            $context[] = "### Solução \"{$slug}\" no catálogo (get_solution)\n\n" . ($actor->canRead(AccessModule::Catalog)
                ? $this->text($this->solution->handle(['solution' => trim($slug)], $actor))
                : 'Esta conexão não lê o catálogo de soluções.');
        }

        return ToolResult::text($context === [] ? $text : $text . "\n\n---\n\n## Contexto pedido\n\n" . implode("\n\n", $context));
    }

    private function text(ToolResult $result): string
    {
        return collect($result->content)->pluck('text')->implode("\n\n");
    }
}
