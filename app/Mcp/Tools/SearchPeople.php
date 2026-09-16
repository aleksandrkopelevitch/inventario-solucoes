<?php

namespace App\Mcp\Tools;

use App\Enums\PersonSolutionRole;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Mcp\Support\Vocabulary;
use App\Mcp\Tool;
use App\Mcp\ToolResult;
use App\Models\Company;
use App\Models\Person;

/**
 * The people catalog — mostly vendor contacts, and that is the shape to expect:
 * 105 of 108 rows have no e-mail at all and most never log in.
 *
 * `Person::scopeFilter()` searches a person's own e-mail, the `contacts`
 * repeater's raw values (so a phone number is findable) and the linked ACCOUNT's
 * address — three places one address can live (§ Searching). Reusing the scope
 * is what stops this tool answering "0 registros" for an address that is plainly
 * on the person's screen, which is exactly the bug that scope was widened to fix.
 */
class SearchPeople implements Tool
{
    public function __construct(private readonly Presenter $presenter) {}

    public function requiresInventory(): bool
    {
        // Catálogo: fechado para quem não lê o inventário no app.
        return true;
    }

    public function name(): string
    {
        return 'search_people';
    }

    public function title(): string
    {
        return 'Buscar pessoas';
    }

    public function description(): string
    {
        return <<<'TXT'
        Busca pessoas do catálogo por nome, e-mail, telefone, empresa ou solução de que
        são responsáveis. A maioria são contatos de fornecedores, não funcionários.
        Retorna um resumo; use get_person com o slug para os contatos completos e as
        soluções pelas quais a pessoa responde.

        Chame sem argumentos para listar todas.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Nome, e-mail, telefone, nome da empresa ou nome de uma solução.',
                ],
                'company' => [
                    'type'        => 'string',
                    'description' => 'Slug de uma empresa: retorna apenas as pessoas ligadas a ela.',
                ],
                'role' => [
                    'type'        => 'string',
                    'description' => 'Papel que a pessoa exerce em alguma solução. Valores: ' . Vocabulary::enumLegend(PersonSolutionRole::class) . '.',
                    'enum'        => array_column(PersonSolutionRole::cases(), 'value'),
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Máximo de resultados (1–100, padrão 25).',
                    'default'     => 25,
                ],
            ],
            'required' => [],
        ];
    }

    public function handle(array $arguments): ToolResult
    {
        $args = new Arguments($arguments);
        $filters = ['search' => $args->string('query')];

        if ($given = $args->string('role')) {
            $role = Vocabulary::enumValue(PersonSolutionRole::class, $given);

            if ($role === null) {
                return ToolResult::failure(
                    "\"{$given}\" não é um papel. Use um destes: "
                    . Vocabulary::enumLegend(PersonSolutionRole::class) . '.',
                );
            }

            $filters['role'] = $role;
        }

        // The scope filters on `company_id`, so the slug the rest of this
        // server speaks in is resolved here rather than leaked into the scope.
        if ($companySlug = $args->string('company')) {
            $company = Company::query()->where('slug', $companySlug)->first(['id']);

            if (! $company) {
                return ToolResult::failure(
                    "Nenhuma empresa com o slug \"{$companySlug}\". Use search_companies para encontrar o slug correto.",
                );
            }

            $filters['company'] = $company->getKey();
        }

        $query = Person::query()->filter($filters)->with('company:id,name');
        $total = $query->count();
        $limit = $args->int('limit', 25, 1, 100);
        $people = $query->orderBy('name')->limit($limit)->get();

        return ToolResult::json([
            'total'     => $total,
            'returned'  => $people->count(),
            'truncated' => $total > $people->count(),
            'people'    => $people->map(fn (Person $p) => $this->presenter->personSummary($p))->all(),
        ]);
    }
}
