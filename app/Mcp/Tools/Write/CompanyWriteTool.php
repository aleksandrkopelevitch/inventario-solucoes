<?php

namespace App\Mcp\Tools\Write;

use App\Enums\AccessModule;
use App\Enums\CompanyKind;
use App\Mcp\Support\Vocabulary;
use App\Mcp\ToolResult;
use App\Mcp\Tools\GetCompany;
use App\Models\Company;
use App\Support\Catalog\CompanyRules;

/** What `create_company` and `update_company` share: the fields, their schema, the rules and the answer. */
abstract class CompanyWriteTool extends WriteTool
{
    public function __construct(protected readonly GetCompany $reader) {}

    public function module(): ?AccessModule
    {
        return AccessModule::Catalog;
    }

    /** argument => [column, kind, extra] — see `WriteTool::columns()`. */
    protected function spec(): array
    {
        return [
            'name'    => ['name', 'text'],
            'kind'    => ['kind', 'enum', CompanyKind::class],
            'website' => ['website', 'text'],
            'notes'   => ['notes', 'text'],
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
            'name' => ['type' => 'string', 'description' => 'Nome da empresa.'],
            'kind' => [
                'type'        => 'string',
                'description' => 'Tipo. Valores: ' . Vocabulary::enumLegend(CompanyKind::class) . '.',
                'enum'        => array_map(fn (CompanyKind $kind) => $kind->value, CompanyKind::cases()),
            ],
            'website' => ['type' => 'string', 'description' => 'Site (URL completa, com https://).'],
            'notes'   => ['type' => 'string', 'description' => 'Observações (aceita Markdown).'],
        ];
    }

    /** @return array<string, mixed> */
    protected function rules(?Company $company = null): array
    {
        return array_diff_key(CompanyRules::for($company), array_flip(CompanyRules::UPLOAD_KEYS));
    }

    protected function answer(Company $company, string $message): ToolResult
    {
        return ToolResult::json(['message' => $message, 'company' => $this->reader->present($company->refresh())]);
    }
}
