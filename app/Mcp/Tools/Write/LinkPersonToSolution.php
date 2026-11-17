<?php

namespace App\Mcp\Tools\Write;

use App\Enums\PersonSolutionRole;
use App\Mcp\Actor;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\RecordFields;
use App\Mcp\Support\Vocabulary;
use App\Mcp\ToolResult;
use App\Models\Person;
use App\Models\Solution;

/**
 * Makes a person responsible for a solution in a role — or changes the role
 * when they already are. ONE link per (person, solution) is what the whole app
 * assumes (`.claude/rules/inline-edit-pattern.md`, the pivot's unique index is
 * wider than that on purpose), so this never adds a second row.
 *
 * Logged on the SOLUTION, as its whole `people` set: a revert re-syncs the set.
 */
class LinkPersonToSolution extends SolutionWriteTool
{
    public function name(): string
    {
        return 'link_person_to_solution';
    }

    public function title(): string
    {
        return 'Vincular pessoa a uma solução';
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function description(): string
    {
        return <<<'TXT'
        Torna uma pessoa responsável por uma solução, com um papel. Se ela já estiver
        vinculada, troca o papel. Uma pessoa tem um único papel por solução.

        Use os slugs de search_solutions e search_people. Confirme com a pessoa conectada.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'solution' => ['type' => 'string', 'description' => 'Slug da solução.'],
                'person'   => ['type' => 'string', 'description' => 'Slug da pessoa.'],
                'role'     => [
                    'type'        => 'string',
                    'description' => 'Papel. Valores: ' . Vocabulary::enumLegend(PersonSolutionRole::class) . '.',
                    'enum'        => array_map(fn (PersonSolutionRole $role) => $role->value, PersonSolutionRole::cases()),
                ],
            ],
            'required' => ['solution', 'person', 'role'],
        ];
    }

    protected function write(array $arguments, Actor $actor): ToolResult
    {
        $args = new Arguments($arguments);
        [$solution, $person, $failure] = self::pair($args->required('solution'), $args->required('person'));

        if ($failure) {
            return $failure;
        }

        if (! $actor->user?->can('update', $solution)) {
            return $this->forbidden('alterar os responsáveis de uma solução');
        }

        $role = Vocabulary::enumValue(PersonSolutionRole::class, $args->required('role'));

        if ($role === null) {
            return $this->refused(['role: valor desconhecido. Valores: ' . Vocabulary::enumLegend(PersonSolutionRole::class) . '.']);
        }

        $before = RecordFields::snapshot($solution, ['people']);
        $linked = $solution->people()->whereKey($person->getKey())->exists();

        $linked
            ? $solution->people()->updateExistingPivot($person->getKey(), ['role' => $role])
            : $solution->people()->attach($person->getKey(), ['role' => $role]);

        $changed = $this->log($actor, $solution, $before, RecordFields::snapshot($solution, ['people']));

        return $this->answer($solution, $actor, match (true) {
            ! $changed => "{$person->name} já era responsável por {$solution->name} nesse papel; nada mudou.",
            $linked    => "Papel de {$person->name} em {$solution->name} alterado.",
            default    => "{$person->name} vinculada a {$solution->name}.",
        });
    }

    /**
     * The solution and the person a link names, or the failure that says
     * which one does not exist.
     *
     * @return array{0: ?Solution, 1: ?Person, 2: ?ToolResult}
     */
    public static function pair(string $solutionSlug, string $personSlug): array
    {
        $solution = Solution::query()->where('slug', $solutionSlug)->first();

        if (! $solution) {
            return [null, null, ToolResult::failure("Nenhuma solução com o slug \"{$solutionSlug}\". Use search_solutions.")];
        }

        $person = Person::query()->where('slug', $personSlug)->first();

        if (! $person) {
            return [null, null, ToolResult::failure("Nenhuma pessoa com o slug \"{$personSlug}\". Use search_people.")];
        }

        return [$solution, $person, null];
    }
}
