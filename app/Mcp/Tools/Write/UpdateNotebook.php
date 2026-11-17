<?php

namespace App\Mcp\Tools\Write;

use App\Mcp\Actor;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\RecordFields;
use App\Mcp\ToolResult;
use Illuminate\Support\Facades\Validator;

/**
 * Renames a caderno and/or sets which solutions it documents. `solutions`
 * REPLACES the set, as the app's panel does — the one argument here where an
 * empty list is a value ("documents nothing") rather than "not sent".
 */
class UpdateNotebook extends DocumentationWriteTool
{
    public function name(): string
    {
        return 'update_notebook';
    }

    public function title(): string
    {
        return 'Editar um caderno';
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
        Renomeia um caderno e/ou define as soluções que ele documenta, em nome da pessoa
        conectada. "solutions" SUBSTITUI a lista inteira: envie a lista completa (leia a
        atual com get_notebook); [] deixa o caderno sem nenhuma; ausente ou null não mexe.
        O slug não muda. Confirme com a pessoa.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'slug'      => ['type' => 'string', 'description' => 'Slug do caderno (de list_notebooks).'],
                'name'      => ['type' => 'string', 'description' => 'Novo nome.'],
                'solutions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'A lista COMPLETA de slugs de soluções que o caderno documenta.'],
            ],
            'required' => ['slug'],
        ];
    }

    protected function write(array $arguments, Actor $actor): ToolResult
    {
        $notebook = $this->notebook((new Arguments($arguments))->required('slug'), $actor);

        if ($notebook instanceof ToolResult) {
            return $notebook;
        }

        if (! $actor->user?->can('update', $notebook)) {
            return $this->forbidden('editar cadernos');
        }

        $name = is_scalar($arguments['name'] ?? null) ? trim((string) $arguments['name']) : '';
        $hasSolutions = array_key_exists('solutions', $arguments) && $arguments['solutions'] !== null;

        if ($name === '' && ! $hasSolutions) {
            return ToolResult::failure('Nada para alterar: envie "name" e/ou "solutions".');
        }

        $fields = [];
        $errors = [];

        if ($name !== '') {
            $validator = Validator::make(['name' => $name], ['name' => ['string', 'max:255']]);
            $errors = [...$errors, ...$validator->errors()->all()];
            $fields[] = 'name';
        }

        if ($hasSolutions) {
            ['ids' => $solutionIds, 'missing' => $missing] = $this->solutionIds($arguments['solutions']);

            if ($missing !== []) {
                $errors[] = 'solutions: nenhuma solução com o slug "' . implode('", "', $missing) . '". Use search_solutions.';
            }

            $fields[] = 'solutions';
        }

        if ($errors !== []) {
            return $this->refused($errors);
        }

        $before = RecordFields::snapshot($notebook, $fields);

        if ($name !== '') {
            $notebook->update(['name' => $name]);
        }

        if ($hasSolutions) {
            $notebook->solutions()->sync($solutionIds);
        }

        $changed = $this->log($actor, $notebook, $before, RecordFields::snapshot($notebook->refresh(), $fields));

        return $this->notebookAnswer($notebook, $changed ? 'Caderno atualizado.' : 'Nada mudou: os valores enviados já eram os atuais.');
    }
}
