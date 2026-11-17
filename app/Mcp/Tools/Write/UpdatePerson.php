<?php

namespace App\Mcp\Tools\Write;

use App\Actions\Catalog\SavePerson;
use App\Mcp\Actor;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\ImageArgument;
use App\Mcp\Support\RecordFields;
use App\Mcp\ToolResult;
use App\Mcp\Tools\GetPerson;
use App\Models\Person;

class UpdatePerson extends PersonWriteTool
{
    public function __construct(GetPerson $reader, private readonly SavePerson $save)
    {
        parent::__construct($reader);
    }

    public function name(): string
    {
        return 'update_person';
    }

    public function title(): string
    {
        return 'Editar uma pessoa';
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
        Altera campos do cadastro de uma pessoa, em nome da pessoa conectada.

        Envie só o que muda: um campo ausente ou null fica como está. Para ESVAZIAR um
        campo, liste-o em "clear". O slug não muda. Vínculos com soluções têm ferramentas
        próprias (link_person_to_solution). Leia antes com get_person e confirme com a pessoa.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'Slug da pessoa a alterar (de search_people).'],
                ...$this->fieldSchema(),
                'clear' => self::clearSchema([...array_keys($this->spec()), 'photo']),
            ],
            'required' => ['slug'],
        ];
    }

    protected function write(array $arguments, Actor $actor): ToolResult
    {
        $slug = (new Arguments($arguments))->required('slug');
        $person = Person::query()->where('slug', $slug)->first();

        if (! $person) {
            return ToolResult::failure("Nenhuma pessoa com o slug \"{$slug}\". Use search_people para encontrar o slug correto.");
        }

        if (! $actor->user?->can('update', $person)) {
            return $this->forbidden('editar pessoas');
        }

        $image = ImageArgument::read($arguments, 'photo', $this->photoRule(), allowClear: true);
        ['data' => $patch, 'errors' => $errors] = $this->columns(ImageArgument::withoutClear($arguments, 'photo'), $this->spec(), allowClear: true);

        if ($image->error) {
            $errors[] = $image->error;
        }

        if ($errors !== []) {
            return $this->refused($errors);
        }

        if ($patch === [] && ! $image->sent()) {
            return ToolResult::failure('Nenhum campo para alterar foi enviado. Envie os campos com o novo valor, ou liste em "clear" os que devem ser esvaziados.');
        }

        $changed = false;

        if ($patch !== []) {
            $current = array_intersect_key($person->getAttributes(), array_flip(array_keys($this->names())));

            if ($invalid = $this->invalid($patch + $current, $this->rules($person), $this->names())) {
                return $invalid;
            }

            $before = RecordFields::snapshot($person, array_keys($patch));
            $this->save->handle($patch + $current, $person);
            $changed = $this->log($actor, $person, $before, RecordFields::snapshot($person->refresh(), array_keys($patch)));
        }

        $changed = $this->applyImage($actor, $person, 'photo_path', $image, 'photos') || $changed;

        return $this->answer($person, $changed ? 'Pessoa atualizada.' : 'Nada mudou: os valores enviados já eram os atuais.');
    }
}
