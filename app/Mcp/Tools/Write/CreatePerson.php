<?php

namespace App\Mcp\Tools\Write;

use App\Actions\Catalog\SavePerson;
use App\Mcp\Actor;
use App\Mcp\ToolResult;
use App\Mcp\Tools\GetPerson;
use App\Models\Person;

class CreatePerson extends PersonWriteTool implements CreatesRecords
{
    public function __construct(GetPerson $reader, private readonly SavePerson $save)
    {
        parent::__construct($reader);
    }

    public function name(): string
    {
        return 'create_person';
    }

    public function title(): string
    {
        return 'Cadastrar uma pessoa';
    }

    public function description(): string
    {
        return <<<'TXT'
        Cadastra uma pessoa nova no catálogo, em nome da pessoa conectada.

        Procure antes com search_people: se já existir alguém com o mesmo nome, a
        chamada é recusada e aponta o cadastro existente. Para vincular a pessoa a uma
        solução, use link_person_to_solution depois. Confirme com a pessoa conectada.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                ...$this->fieldSchema(),
                'slug'            => ['type' => 'string', 'description' => 'Opcional. Gerado a partir do nome quando omitido.'],
                'allow_duplicate' => ['type' => 'boolean', 'description' => 'Só com confirmação da pessoa: cria mesmo que já exista alguém com o mesmo nome.'],
            ],
            'required' => ['name'],
        ];
    }

    protected function write(array $arguments, Actor $actor): ToolResult
    {
        if (! $actor->user?->can('create', Person::class)) {
            return $this->forbidden('cadastrar pessoas');
        }

        ['data' => $data, 'errors' => $errors] = $this->columns($arguments, $this->spec());

        if ($errors !== []) {
            return $this->refused($errors);
        }

        if (filled($arguments['slug'] ?? null) && is_scalar($arguments['slug'])) {
            $data['slug'] = trim((string) $arguments['slug']);
        }

        if ($invalid = $this->invalid($data, $this->rules(), $this->names())) {
            return $invalid;
        }

        return $this->createOnce(
            $actor,
            Person::class,
            $data['name'],
            $data,
            $this->names(),
            (bool) ($arguments['allow_duplicate'] ?? false),
            fn ()                              => $this->save->handle($data),
            fn (Person $person, ?string $note) => $this->answer($person, $note ?? 'Pessoa cadastrada.'),
        );
    }
}
