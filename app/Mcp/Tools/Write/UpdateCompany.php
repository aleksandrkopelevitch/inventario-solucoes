<?php

namespace App\Mcp\Tools\Write;

use App\Actions\Catalog\SaveCompany;
use App\Mcp\Actor;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\ImageArgument;
use App\Mcp\Support\RecordFields;
use App\Mcp\ToolResult;
use App\Mcp\Tools\GetCompany;
use App\Models\Company;

class UpdateCompany extends CompanyWriteTool
{
    public function __construct(GetCompany $reader, private readonly SaveCompany $save)
    {
        parent::__construct($reader);
    }

    public function name(): string
    {
        return 'update_company';
    }

    public function title(): string
    {
        return 'Editar uma empresa';
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
        Altera campos do cadastro de uma empresa, em nome da pessoa conectada.

        Envie só o que muda: um campo ausente ou null fica como está. Para ESVAZIAR um
        campo, liste-o em "clear". O slug não muda. Leia antes com get_company e
        confirme com a pessoa.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'Slug da empresa a alterar (de search_companies).'],
                ...$this->fieldSchema(),
                'clear' => self::clearSchema([...array_keys($this->spec()), 'logo']),
            ],
            'required' => ['slug'],
        ];
    }

    protected function write(array $arguments, Actor $actor): ToolResult
    {
        $slug = (new Arguments($arguments))->required('slug');
        $company = Company::query()->where('slug', $slug)->first();

        if (! $company) {
            return ToolResult::failure("Nenhuma empresa com o slug \"{$slug}\". Use search_companies para encontrar o slug correto.");
        }

        if (! $actor->user?->can('update', $company)) {
            return $this->forbidden('editar empresas');
        }

        $image = ImageArgument::read($arguments, 'logo', $this->logoRule(), allowClear: true);
        ['data' => $patch, 'errors' => $errors] = $this->columns(ImageArgument::withoutClear($arguments, 'logo'), $this->spec(), allowClear: true);

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
            $current = array_intersect_key($company->getAttributes(), array_flip(array_keys($this->names())));

            if ($invalid = $this->invalid($patch + $current, $this->rules($company), $this->names())) {
                return $invalid;
            }

            $before = RecordFields::snapshot($company, array_keys($patch));
            $this->save->handle($patch + $current, $company);
            $changed = $this->log($actor, $company, $before, RecordFields::snapshot($company->refresh(), array_keys($patch)));
        }

        $changed = $this->applyImage($actor, $company, 'logo_path', $image, 'company-logos') || $changed;

        return $this->answer($company, $changed ? 'Empresa atualizada.' : 'Nada mudou: os valores enviados já eram os atuais.');
    }
}
