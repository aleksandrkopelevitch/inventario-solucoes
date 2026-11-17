<?php

namespace App\Mcp\Tools\Write;

use App\Mcp\Actor;
use App\Mcp\Support\RecordFields;
use App\Mcp\ToolResult;
use App\Models\Notebook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

/**
 * A new caderno, optionally documenting some solutions. It is born
 * UNPUBLISHED — publishing is an admin's decision (`NotebookPolicy::administer`)
 * and no tool here makes it — and with no page: the pages are what the model
 * writes next with `create_page`.
 */
class CreateNotebook extends DocumentationWriteTool implements CreatesRecords
{
    public function name(): string
    {
        return 'create_notebook';
    }

    public function title(): string
    {
        return 'Criar um caderno';
    }

    public function description(): string
    {
        return <<<'TXT'
        Cria um caderno de documentação, em nome da pessoa conectada, opcionalmente já
        ligado às soluções que ele documenta. Nasce NÃO publicado e sem páginas: crie as
        páginas com create_page. Publicar é decisão de um administrador, no app.

        Procure antes com list_notebooks; um caderno com o mesmo nome é recusado.
        Confirme com a pessoa antes de criar.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'name'            => ['type' => 'string', 'description' => 'Nome do caderno.'],
                'solutions'       => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Slugs das soluções que o caderno documenta (de search_solutions).'],
                'allow_duplicate' => ['type' => 'boolean', 'description' => 'Só com confirmação da pessoa: cria mesmo que já exista um caderno com o mesmo nome.'],
            ],
            'required' => ['name'],
        ];
    }

    protected function write(array $arguments, Actor $actor): ToolResult
    {
        if (! $actor->user?->can('create', Notebook::class)) {
            return $this->forbidden('criar cadernos');
        }

        $name = is_scalar($arguments['name'] ?? null) ? trim((string) $arguments['name']) : '';
        $validator = Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:255']], [], ['name' => 'name']);

        if ($validator->fails()) {
            return $this->refused($validator->errors()->all());
        }

        ['ids' => $solutionIds, 'missing' => $missing] = $this->solutionIds($arguments['solutions'] ?? []);

        if ($missing !== []) {
            return $this->refused(['solutions: nenhuma solução com o slug "' . implode('", "', $missing) . '". Use search_solutions.']);
        }

        return $this->createOnce(
            $actor,
            Notebook::class,
            $name,
            ['name' => $name],
            ['name' => 'name'],
            (bool) ($arguments['allow_duplicate'] ?? false),
            function () use ($name, $solutionIds) {
                $notebook = Notebook::create(['name' => $name, 'slug' => Notebook::uniqueSlug($name)]);
                $notebook->solutions()->sync($solutionIds);

                return $notebook;
            },
            fn (Notebook $notebook, ?string $note) => $this->notebookAnswer($notebook, $note ?? 'Caderno criado (não publicado).'),
        );
    }

    /** The linked solutions belong to what the create made, so its log row names them too. */
    protected function log(Actor $actor, Model $subject, ?array $before, array $after): bool
    {
        if ($before === null && $subject instanceof Notebook) {
            $after += RecordFields::snapshot($subject, ['solutions']);
        }

        return parent::log($actor, $subject, $before, $after);
    }
}
