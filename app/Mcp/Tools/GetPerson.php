<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Mcp\Tool;
use App\Mcp\ToolResult;
use App\Models\Person;

/**
 * One person's record: their contacts and the solutions they answer for.
 *
 * What is deliberately NOT here is their ACCOUNT. `Person::user()` is loaded
 * nowhere in this module and no payload names a role, an access link or whether
 * the person can log in at all — that is the authentication side of the app
 * (§ Access is an attribute of a PERSON), it is admin-only inside the app, and
 * it answers no question about the inventory. A read credential that could
 * enumerate who holds an admin account would be a reconnaissance tool.
 */
class GetPerson implements Tool
{
    public function __construct(private readonly Presenter $presenter) {}

    public function requiresInventory(): bool
    {
        // Catálogo: fechado para quem não lê o inventário no app.
        return true;
    }

    public function name(): string
    {
        return 'get_person';
    }

    public function title(): string
    {
        return 'Abrir uma pessoa';
    }

    public function description(): string
    {
        return <<<'TXT'
        Cadastro completo de uma pessoa pelo slug: cargo, empresa, e-mail, telefone,
        contatos adicionais e as soluções pelas quais responde, com o papel em cada uma.

        Obtenha o slug com search_people.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'Slug da pessoa, como retornado por search_people.'],
            ],
            'required' => ['slug'],
        ];
    }

    public function handle(array $arguments): ToolResult
    {
        $slug = (new Arguments($arguments))->required('slug');

        $person = Person::query()
            ->with(['company', 'contacts', 'solutions:id,slug,name'])
            ->where('slug', $slug)
            ->first();

        if (! $person) {
            return ToolResult::failure(
                "Nenhuma pessoa com o slug \"{$slug}\". Use search_people para encontrar o slug correto.",
            );
        }

        return ToolResult::json($this->presenter->person($person));
    }
}
