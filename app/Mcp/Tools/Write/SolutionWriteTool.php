<?php

namespace App\Mcp\Tools\Write;

use App\Enums\AccessModule;
use App\Enums\AttributeGroup;
use App\Mcp\Actor;
use App\Mcp\ToolResult;
use App\Mcp\Tools\GetSolution;
use App\Models\Solution;

/** What `create_solution` and `update_solution` share: the fields, their schema and the answer. */
abstract class SolutionWriteTool extends WriteTool
{
    public function __construct(protected readonly GetSolution $reader) {}

    public function module(): ?AccessModule
    {
        return AccessModule::Catalog;
    }

    /** argument => [column, kind, extra] — see `WriteTool::columns()`. */
    protected function spec(): array
    {
        return [
            'name'                   => ['name', 'text'],
            'description'            => ['description', 'text'],
            'vendor'                 => ['vendor_company_id', 'company'],
            'category'               => ['category', 'attribute', AttributeGroup::Category],
            'status'                 => ['status', 'attribute', AttributeGroup::Status],
            'directorate'            => ['directorate', 'attribute', AttributeGroup::Directorate],
            'support_type'           => ['support_type', 'attribute', AttributeGroup::SupportType],
            'environment'            => ['environment', 'attribute', AttributeGroup::Environment],
            'cloud'                  => ['cloud', 'attribute', AttributeGroup::Cloud],
            'contract_status'        => ['contract_status', 'attribute', AttributeGroup::ContractStatus],
            'criticality'            => ['criticality', 'attribute', AttributeGroup::Criticality],
            'support_operation_note' => ['support_operation_note', 'text'],
        ];
    }

    /** column => argument, so a validation message names what the model sent. */
    protected function names(): array
    {
        return collect($this->spec())->mapWithKeys(fn (array $spec, string $argument) => [$spec[0] => $argument])->all()
            + ['slug' => 'slug'];
    }

    /** @return array<string, mixed> */
    protected function fieldSchema(): array
    {
        return [
            'name'                   => ['type' => 'string', 'description' => 'Nome da solução.'],
            'description'            => ['type' => 'string', 'description' => 'Descrição (aceita Markdown).'],
            'vendor'                 => ['type' => 'string', 'description' => 'Slug da empresa fornecedora (veja search_companies).'],
            'category'               => self::attributeSchema(AttributeGroup::Category, 'Categoria.'),
            'status'                 => self::attributeSchema(AttributeGroup::Status, 'Situação.'),
            'directorate'            => self::attributeSchema(AttributeGroup::Directorate, 'Diretoria responsável.'),
            'support_type'           => self::attributeSchema(AttributeGroup::SupportType, 'Tipo de suporte.'),
            'environment'            => self::attributeSchema(AttributeGroup::Environment, 'Ambiente de hospedagem.'),
            'cloud'                  => self::attributeSchema(AttributeGroup::Cloud, 'Nuvem.'),
            'contract_status'        => self::attributeSchema(AttributeGroup::ContractStatus, 'Situação do contrato.'),
            'criticality'            => self::attributeSchema(AttributeGroup::Criticality, 'Criticidade.'),
            'support_operation_note' => ['type' => 'string', 'description' => 'Nota de suporte e operação (aceita Markdown).'],
        ];
    }

    protected function answer(Solution $solution, Actor $actor, string $message): ToolResult
    {
        return ToolResult::json(['message' => $message, 'solution' => $this->reader->present($solution->refresh(), $actor)]);
    }
}
