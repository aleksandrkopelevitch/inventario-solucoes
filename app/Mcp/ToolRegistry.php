<?php

namespace App\Mcp;

use App\Enums\AccessModule;
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
        Tools\ListNotebooks::class,
        Tools\GetNotebook::class,
        Tools\GetDocumentationPage::class,
        Tools\SearchDocumentation::class,
        // Before any page is WRITTEN with a diagram or a scene: the playbook
        // and the syntax of {% uml %} / {% scene %}.
        Tools\GetCodeDocumentationGuide::class,
        Tools\SearchPeople::class,
        Tools\GetPerson::class,
        Tools\SearchCompanies::class,
        Tools\GetCompany::class,
        // Writes come after every reader: the useful shape of a change is
        // "find it, open it, then change it", and a model reading this list
        // top-down meets the readers first. Shown only to a connection that
        // may write (see `all()`).
        Tools\Write\CreateSolution::class,
        Tools\Write\UpdateSolution::class,
        Tools\Write\LinkPersonToSolution::class,
        Tools\Write\UnlinkPersonFromSolution::class,
        Tools\Write\SetSolutionRelationship::class,
        Tools\Write\RemoveSolutionRelationship::class,
        Tools\Write\CreatePerson::class,
        Tools\Write\UpdatePerson::class,
        Tools\Write\CreateCompany::class,
        Tools\Write\UpdateCompany::class,
        Tools\Write\CreateNotebook::class,
        Tools\Write\UpdateNotebook::class,
        Tools\Write\CreatePage::class,
        Tools\Write\UpdatePage::class,
        Tools\Write\PlacePage::class,
        Tools\Digibee\CountDigibeeExecutions::class,
        Tools\Digibee\SearchDigibeeLogs::class,
        Tools\Digibee\QueryDigibeeReport::class,
        Tools\Digibee\GetDigibeeExecution::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * The tools this caller may use: those of the modules it reads.
     *
     * Filtering here rather than at the call site is what keeps a connection
     * coherent: `tools/list` shows only what the account may read, so the model
     * never chooses a tool it would be refused, and `find()` answers null for
     * the rest — which `McpServer` reports as an unknown tool, the same sentence
     * a typo gets.
     *
     * @return list<Tool>
     */
    public function all(Actor $actor): array
    {
        return array_values(array_filter($this->every(), fn (Tool $tool) => $tool instanceof Writes
            ? $actor->canWrite($tool->module())
            : $actor->canRead($tool->module())));
    }

    /**
     * The modules some writing tool changes — what `mcp:write` can mean at
     * all (App\Mcp\McpScopeRepository).
     *
     * @return list<AccessModule>
     */
    public function writableModules(): array
    {
        return collect($this->every())
            ->filter(fn (Tool $tool) => $tool instanceof Writes)
            ->map(fn (Tool $tool) => $tool->module())
            ->filter()
            ->unique(fn (AccessModule $module) => $module->value)
            ->values()
            ->all();
    }

    /**
     * Every tool the server has, whoever asks — for saying what a caller is
     * NOT shown, never for serving one.
     *
     * @return list<Tool>
     */
    public function every(): array
    {
        return array_map(fn (string $tool) => $this->container->make($tool), self::TOOLS);
    }

    public function find(string $name, Actor $actor): ?Tool
    {
        foreach ($this->all($actor) as $tool) {
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
     * safe and skip a per-call confirmation — true for every reader, and false
     * for a writing tool (`Writes`), which is exactly what makes a client ask
     * the person before calling one. That question is the first safety net of
     * the write tools, so it is never claimed away. `openWorldHint` is false
     * for the same honesty — most tools reach this app's database and nothing
     * beyond it — except for a tool that says otherwise (`ReachesOutside`): the
     * Digibee logs tools read the platform live.
     *
     * @return list<array<string, mixed>>
     */
    public function describe(Actor $actor): array
    {
        return array_map(fn (Tool $tool) => [
            'name'        => $tool->name(),
            'title'       => $tool->title(),
            'description' => $tool->description(),
            'inputSchema' => $tool->inputSchema(),
            'annotations' => [
                'title'           => $tool->title(),
                'readOnlyHint'    => ! $tool instanceof Writes,
                'destructiveHint' => $tool instanceof Writes && $tool->destructive(),
                'idempotentHint'  => ! $tool instanceof Writes || $tool->idempotent(),
                'openWorldHint'   => $tool instanceof ReachesOutside,
            ],
        ], $this->all($actor));
    }
}
