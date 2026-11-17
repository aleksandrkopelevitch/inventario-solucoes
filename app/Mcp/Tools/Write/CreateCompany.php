<?php

namespace App\Mcp\Tools\Write;

use App\Actions\Catalog\SaveCompany;
use App\Mcp\Actor;
use App\Mcp\ToolResult;
use App\Mcp\Tools\GetCompany;
use App\Models\Company;

class CreateCompany extends CompanyWriteTool implements CreatesRecords
{
    public function __construct(GetCompany $reader, private readonly SaveCompany $save)
    {
        parent::__construct($reader);
    }

    public function name(): string
    {
        return 'create_company';
    }

    public function title(): string
    {
        return 'Cadastrar uma empresa';
    }

    public function description(): string
    {
        return <<<'TXT'
        Cadastra uma empresa nova (interna, fornecedora ou parceira), em nome da pessoa
        conectada.

        Procure antes com search_companies: se já existir uma com o mesmo nome, a chamada
        é recusada e aponta a existente. Confirme com a pessoa conectada.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                ...$this->fieldSchema(),
                'slug'            => ['type' => 'string', 'description' => 'Opcional. Gerado a partir do nome quando omitido.'],
                'allow_duplicate' => ['type' => 'boolean', 'description' => 'Só com confirmação da pessoa: cria mesmo que já exista uma empresa com o mesmo nome.'],
            ],
            'required' => ['name', 'kind'],
        ];
    }

    protected function write(array $arguments, Actor $actor): ToolResult
    {
        if (! $actor->user?->can('create', Company::class)) {
            return $this->forbidden('cadastrar empresas');
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
            Company::class,
            $data['name'],
            $data,
            $this->names(),
            (bool) ($arguments['allow_duplicate'] ?? false),
            fn ()                                => $this->save->handle($data),
            fn (Company $company, ?string $note) => $this->answer($company, $note ?? 'Empresa cadastrada.'),
        );
    }
}
