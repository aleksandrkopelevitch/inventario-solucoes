<?php

namespace App\Mcp\Tools\Write;

use App\Mcp\Actor;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\RecordFields;
use App\Mcp\ToolResult;

/**
 * Removes a person's link to a solution — the link only; both records stay.
 * Logged on the SOLUTION, as its whole `people` set.
 */
class UnlinkPersonFromSolution extends SolutionWriteTool
{
    public function name(): string
    {
        return 'unlink_person_from_solution';
    }

    public function title(): string
    {
        return 'Desvincular pessoa de uma solução';
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function destructive(): bool
    {
        return true;
    }

    public function description(): string
    {
        return <<<'TXT'
        Remove o vínculo de uma pessoa com uma solução. A pessoa e a solução continuam
        no catálogo; só o vínculo some. Confirme com a pessoa conectada antes.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'solution' => ['type' => 'string', 'description' => 'Slug da solução.'],
                'person'   => ['type' => 'string', 'description' => 'Slug da pessoa.'],
            ],
            'required' => ['solution', 'person'],
        ];
    }

    protected function write(array $arguments, Actor $actor): ToolResult
    {
        $args = new Arguments($arguments);
        [$solution, $person, $failure] = LinkPersonToSolution::pair($args->required('solution'), $args->required('person'));

        if ($failure) {
            return $failure;
        }

        if (! $actor->user?->can('update', $solution)) {
            return $this->forbidden('alterar os responsáveis de uma solução');
        }

        $before = RecordFields::snapshot($solution, ['people']);
        $solution->people()->detach($person->getKey());
        $changed = $this->log($actor, $solution, $before, RecordFields::snapshot($solution, ['people']));

        return $this->answer($solution, $actor, $changed
            ? "{$person->name} desvinculada de {$solution->name}."
            : "{$person->name} não estava vinculada a {$solution->name}; nada mudou.");
    }
}
