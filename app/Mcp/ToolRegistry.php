<?php

namespace App\Mcp;

use Illuminate\Contracts\Container\Container;

/**
 * Every tool the MCP server exposes, in the order a client lists them.
 *
 * The order is deliberate and it is a prompt: `tools/list` is what a model reads
 * before choosing, and the searches come before the getters because the useful
 * shape of almost every question is "find the thing, then open it". A catalog
 * that led with `get_solution` invites a model to guess a slug.
 *
 * Tools are resolved through the container (constructor DI, like an Action), so
 * one that needs `DocumentationSearchService` simply asks for it.
 */
class ToolRegistry
{
    /** @var list<class-string<Tool>> */
    public const TOOLS = [
        Tools\SearchSolutions::class,
        Tools\GetSolution::class,
        Tools\SearchDiagrams::class,
        Tools\GetDiagram::class,
        Tools\ListNotebooks::class,
        Tools\GetNotebook::class,
        Tools\GetDocumentationPage::class,
        Tools\SearchDocumentation::class,
        Tools\SearchPeople::class,
        Tools\GetPerson::class,
        Tools\SearchCompanies::class,
        Tools\GetCompany::class,
    ];

    public function __construct(private readonly Container $container) {}

    /** @return list<Tool> */
    public function all(): array
    {
        return array_map(fn (string $tool) => $this->container->make($tool), self::TOOLS);
    }

    public function find(string $name): ?Tool
    {
        foreach ($this->all() as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * The `tools/list` payload.
     *
     * `annotations.readOnlyHint` is what lets a client show the connector as
     * safe and skip a per-call confirmation — worth setting precisely because
     * every tool here really is read-only (see `Tool`). `openWorldHint` is false
     * for the same honesty: these tools reach this app's database and nothing
     * beyond it.
     *
     * @return list<array<string, mixed>>
     */
    public function describe(): array
    {
        return array_map(fn (Tool $tool) => [
            'name'        => $tool->name(),
            'title'       => $tool->title(),
            'description' => $tool->description(),
            'inputSchema' => $tool->inputSchema(),
            'annotations' => [
                'title'           => $tool->title(),
                'readOnlyHint'    => true,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ],
        ], $this->all());
    }
}
