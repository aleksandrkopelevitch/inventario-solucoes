<?php

namespace App\Mcp\Tools\Write;

use App\Enums\AccessModule;
use App\Mcp\ToolResult;
use App\Mcp\Tools\GetPerson;
use App\Models\Person;
use App\Support\Catalog\PersonRules;

/** What `create_person` and `update_person` share: the fields, their schema, the rules and the answer. */
abstract class PersonWriteTool extends WriteTool
{
    public function __construct(protected readonly GetPerson $reader) {}

    public function module(): ?AccessModule
    {
        return AccessModule::Catalog;
    }

    /** argument => [column, kind] — see `WriteTool::columns()`. */
    protected function spec(): array
    {
        return [
            'name'      => ['name', 'text'],
            'company'   => ['company_id', 'company'],
            'job_title' => ['job_title', 'text'],
            'email'     => ['email', 'text'],
            'phone'     => ['phone', 'text'],
            'notes'     => ['notes', 'text'],
        ];
    }

    /** column => argument. */
    protected function names(): array
    {
        return collect($this->spec())->mapWithKeys(fn (array $spec, string $argument) => [$spec[0] => $argument])->all()
            + ['slug' => 'slug'];
    }

    /** @return array<string, mixed> */
    protected function fieldSchema(): array
    {
        return [
            'name'      => ['type' => 'string', 'description' => 'Nome da pessoa.'],
            'company'   => ['type' => 'string', 'description' => 'Slug da empresa em que trabalha (veja search_companies).'],
            'job_title' => ['type' => 'string', 'description' => 'Cargo.'],
            'email'     => ['type' => 'string', 'description' => 'E-mail.'],
            'phone'     => ['type' => 'string', 'description' => 'Telefone.'],
            'notes'     => ['type' => 'string', 'description' => 'Observações (aceita Markdown).'],
        ];
    }

    /** @return array<string, mixed> */
    protected function rules(?Person $person = null): array
    {
        return array_diff_key(PersonRules::for($person), array_flip(PersonRules::FORM_ONLY_KEYS));
    }

    protected function answer(Person $person, string $message): ToolResult
    {
        return ToolResult::json(['message' => $message, 'person' => $this->reader->present($person->refresh())]);
    }
}
