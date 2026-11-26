<?php

namespace App\Mcp\Tools\Write;

use App\Actions\Catalog\SaveManualRelationship;
use App\Enums\RelationshipDirection;
use App\Mcp\Actor;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\RecordFields;
use App\Mcp\ToolResult;
use App\Mcp\Tools\GetSolution;
use App\Models\Solution;
use App\Services\SolutionRelationshipService;

/**
 * Records — or corrects — the interconnection between two solutions, the
 * same record the solution page's "Relacionamentos" card writes
 * (SaveManualRelationship). One per pair; over a pair the documentation
 * already states it is the OVERRIDE, and the answer says what the
 * documentation said, so the model can tell the person it is contradicting a
 * diagram.
 *
 * Logged on `solution`, as its whole `relationships` set (RecordFields), like
 * `link_person_to_solution` logs `people`: a revert re-syncs the set.
 */
class SetSolutionRelationship extends SolutionWriteTool
{
    /** What the model sends, read from `solution`'s side. */
    public const DIRECTIONS = [
        'chama'         => RelationshipDirection::Outgoing,
        'é chamada por' => RelationshipDirection::Incoming,
        'mútua'         => RelationshipDirection::Both,
    ];

    public function __construct(
        GetSolution $reader,
        private readonly SaveManualRelationship $save,
        private readonly SolutionRelationshipService $relationships,
    ) {
        parent::__construct($reader);
    }

    public function name(): string
    {
        return 'set_solution_relationship';
    }

    public function title(): string
    {
        return 'Cadastrar interconexão entre soluções';
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function description(): string
    {
        return <<<'TXT'
        Cadastra — ou corrige — que duas soluções se comunicam, e em que sentido, lido do
        lado de "solution": "chama" (solution chama other), "é chamada por" (other chama
        solution) ou "mútua". Existe uma interconexão por par: chamar de novo corrige a
        existente.

        Os relacionamentos também vêm dos diagramas UML da documentação (vínculo
        "solution" nos participantes). Quando a página existe, prefira documentar no
        diagrama. Um cadastro sobre um par que a documentação já descreve PREVALECE sobre
        ela; a resposta diz o que a documentação dizia. Confirme com a pessoa conectada.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'solution'  => ['type' => 'string', 'description' => 'Slug da solução (veja search_solutions).'],
                'other'     => ['type' => 'string', 'description' => 'Slug da outra solução.'],
                'direction' => [
                    'type'        => 'string',
                    'description' => 'Sentido, do lado de "solution".',
                    'enum'        => array_keys(self::DIRECTIONS),
                ],
            ],
            'required' => ['solution', 'other', 'direction'],
        ];
    }

    protected function write(array $arguments, Actor $actor): ToolResult
    {
        $args = new Arguments($arguments);
        [$solution, $other, $failure] = self::pair($args->required('solution'), $args->required('other'));

        if ($failure) {
            return $failure;
        }

        if (! $actor->user?->can('update', $solution)) {
            return $this->forbidden('alterar os relacionamentos de uma solução');
        }

        $direction = self::DIRECTIONS[mb_strtolower(trim($args->required('direction')))] ?? null;

        if ($direction === null) {
            return $this->refused(['direction: use "chama", "é chamada por" ou "mútua".']);
        }

        // Read BEFORE the write: once the record exists it decides the pair,
        // and what the documentation says on its own is what the person needs
        // to hear about.
        $current = $this->relationships->forSolution($solution)->first(fn ($r) => $r->other->is($other));
        $documented = match (true) {
            $current === null         => null,
            $current->manual === null => $current->direction,
            default                   => $current->documented,
        };

        $before = RecordFields::snapshot($solution, ['relationships']);
        $this->save->handle($solution, $other, $direction);
        $changed = $this->log($actor, $solution, $before, RecordFields::snapshot($solution, ['relationships']));

        $message = match (true) {
            ! $changed => "A interconexão entre {$solution->name} e {$other->name} já estava assim; nada mudou.",
            default    => "Interconexão entre {$solution->name} e {$other->name} salva: {$direction->sentence($solution->name, $other->name)}.",
        };

        if ($documented !== null && $documented !== $direction) {
            $message .= ' A documentação dizia outra coisa (' . $documented->sentence($solution->name, $other->name)
                . '); o cadastro prevalece até ser removido.';
        }

        return $this->answer($solution, $actor, $message);
    }

    /**
     * The two solutions a relationship names, or the failure that says which
     * one does not exist — or that they are the same.
     *
     * @return array{0: ?Solution, 1: ?Solution, 2: ?ToolResult}
     */
    public static function pair(string $solutionSlug, string $otherSlug): array
    {
        $solution = Solution::query()->where('slug', $solutionSlug)->first();
        $other = Solution::query()->where('slug', $otherSlug)->first();

        return match (true) {
            $solution === null    => [null, null, ToolResult::failure("Nenhuma solução com o slug \"{$solutionSlug}\". Use search_solutions.")],
            $other === null       => [null, null, ToolResult::failure("Nenhuma solução com o slug \"{$otherSlug}\". Use search_solutions.")],
            $solution->is($other) => [null, null, ToolResult::failure('Uma solução não se relaciona com ela mesma.')],
            default               => [$solution, $other, null],
        };
    }
}
