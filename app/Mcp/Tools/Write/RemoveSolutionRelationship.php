<?php

namespace App\Mcp\Tools\Write;

use App\Mcp\Actor;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\RecordFields;
use App\Mcp\ToolResult;
use App\Mcp\Tools\GetSolution;
use App\Models\SolutionRelationship;
use App\Services\SolutionRelationshipService;
use App\Support\Documentation\UmlDiagram;

/**
 * Removes the interconnection a person RECORDED between two solutions. A link
 * is not a record, so "nada é apagado" holds — the same reasoning as
 * `unlink_person_from_solution`. Over a pair the documentation states, this
 * removes the override and the documented answer comes back.
 *
 * A relationship that only the documentation states is refused, naming the
 * page and the diagram: it changes by editing that page (`update_page`), and
 * a tool that seemed to remove it would be undone by the next read.
 *
 * Logged on `solution`, as its whole `relationships` set.
 */
class RemoveSolutionRelationship extends SolutionWriteTool
{
    public function __construct(GetSolution $reader, private readonly SolutionRelationshipService $relationships)
    {
        parent::__construct($reader);
    }

    public function name(): string
    {
        return 'remove_solution_relationship';
    }

    public function title(): string
    {
        return 'Remover interconexão cadastrada';
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function description(): string
    {
        return <<<'TXT'
        Remove a interconexão CADASTRADA entre duas soluções (set_solution_relationship ou
        a página da solução). Se a documentação também descreve o par, ele volta a valer
        como a documentação diz. Um relacionamento que só a documentação descreve não é
        removido aqui: edite o diagrama UML da página (update_page). Confirme com a pessoa
        conectada.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'solution' => ['type' => 'string', 'description' => 'Slug da solução.'],
                'other'    => ['type' => 'string', 'description' => 'Slug da outra solução.'],
            ],
            'required' => ['solution', 'other'],
        ];
    }

    protected function write(array $arguments, Actor $actor): ToolResult
    {
        $args = new Arguments($arguments);
        [$solution, $other, $failure] = SetSolutionRelationship::pair($args->required('solution'), $args->required('other'));

        if ($failure) {
            return $failure;
        }

        if (! $actor->user?->can('update', $solution)) {
            return $this->forbidden('alterar os relacionamentos de uma solução');
        }

        $record = SolutionRelationship::query()->forPair($solution->getKey(), $other->getKey())->first();

        if ($record === null) {
            $documented = $this->relationships->forSolution($solution)->first(fn ($r) => $r->other->is($other));

            if ($documented === null) {
                return $this->answer($solution, $actor, "Não há interconexão entre {$solution->name} e {$other->name}; nada mudou.");
            }

            $where = collect($documented->evidence)
                ->map(fn (array $e) => 'diagrama "' . ($e['title'] ?? UmlDiagram::TYPES[$e['type']] ?? 'UML') . '" na página "'
                    . $e['page']->title . '" (' . $e['page']->slug . ') do caderno ' . $e['page']->notebook->slug)
                ->implode('; ');

            return ToolResult::failure("Esta relação vem da documentação, não de um cadastro: {$where}. "
                . 'Edite a página (update_page) para mudá-la.');
        }

        $before = RecordFields::snapshot($solution, ['relationships']);
        $record->delete();
        $this->log($actor, $solution, $before, RecordFields::snapshot($solution, ['relationships']));

        return $this->answer($solution, $actor, "Interconexão cadastrada entre {$solution->name} e {$other->name} removida.");
    }
}
