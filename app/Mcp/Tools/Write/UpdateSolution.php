<?php

namespace App\Mcp\Tools\Write;

use App\Actions\Catalog\SaveSolution;
use App\Mcp\Actor;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\RecordFields;
use App\Mcp\ToolResult;
use App\Mcp\Tools\GetSolution;
use App\Models\Solution;
use App\Support\Catalog\SolutionRules;

class UpdateSolution extends SolutionWriteTool
{
    public function __construct(GetSolution $reader, private readonly SaveSolution $save)
    {
        parent::__construct($reader);
    }

    public function name(): string
    {
        return 'update_solution';
    }

    public function title(): string
    {
        return 'Editar uma solução';
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function destructive(): bool
    {
        return true;
    }

    /** NOT NULL with a default (SaveSolution): "cleared" would mean "unchanged". */
    protected function unclearable(): array
    {
        return ['support_type', 'contract_status'];
    }

    public function description(): string
    {
        return <<<'TXT'
        Altera campos de uma solução do catálogo, em nome da pessoa conectada.

        Envie só o que muda: um campo ausente ou null fica como está. Para ESVAZIAR um
        campo, liste-o em "clear". O slug não muda. Leia antes com get_solution e
        confirme a alteração com a pessoa. Responde com o cadastro salvo.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'Slug da solução a alterar (de search_solutions).'],
                ...$this->fieldSchema(),
                'clear' => self::clearSchema(array_keys($this->spec())),
            ],
            'required' => ['slug'],
        ];
    }

    protected function write(array $arguments, Actor $actor): ToolResult
    {
        $slug = (new Arguments($arguments))->required('slug');
        $solution = Solution::query()->where('slug', $slug)->first();

        if (! $solution) {
            return ToolResult::failure("Nenhuma solução com o slug \"{$slug}\". Use search_solutions para encontrar o slug correto.");
        }

        if (! $actor->user?->can('update', $solution)) {
            return $this->forbidden('editar soluções');
        }

        ['data' => $patch, 'errors' => $errors] = $this->columns($arguments, $this->spec(), allowClear: true);

        if ($errors !== []) {
            return $this->refused($errors);
        }

        if ($patch === []) {
            return ToolResult::failure('Nenhum campo para alterar foi enviado. Envie os campos com o novo valor, ou liste em "clear" os que devem ser esvaziados.');
        }

        // The WHOLE record is validated, not just the patch: the panel's rules
        // require name, category and status on every save, and a patch must
        // not be a way around that.
        $columns = array_keys($this->names());
        $current = array_intersect_key($solution->getAttributes(), array_flip($columns));

        if ($invalid = $this->invalid($patch + $current, SolutionRules::for($solution), $this->names())) {
            return $invalid;
        }

        $before = RecordFields::snapshot($solution, array_keys($patch));
        $this->save->handle($patch + $current, $solution);
        $changed = $this->log($actor, $solution, $before, RecordFields::snapshot($solution->refresh(), array_keys($patch)));

        return $this->answer($solution, $actor, $changed ? 'Solução atualizada.' : 'Nada mudou: os valores enviados já eram os atuais.');
    }
}
