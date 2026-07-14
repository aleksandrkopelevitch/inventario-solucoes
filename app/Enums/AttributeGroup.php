<?php

namespace App\Enums;

/**
 * Os 8 atributos de Solução (e, para `Criticality`, também de Integration)
 * cujos valores são gerenciáveis em runtime via `AttributeOption` — ver
 * `App\Http\Controllers\AttributeOptionController`. Adicionar um grupo novo
 * é mudança de código; os *valores* dentro de cada grupo são dados editáveis.
 */
enum AttributeGroup: string
{
    case Category = 'category';
    case Status = 'status';
    case Directorate = 'directorate';
    case Environment = 'environment';
    case Cloud = 'cloud';
    case ContractStatus = 'contract_status';
    case SupportType = 'support_type';
    case Criticality = 'criticality';

    public function label(): string
    {
        return match ($this) {
            self::Category       => 'Categoria',
            self::Status         => 'Status',
            self::Directorate    => 'Diretoria',
            self::Environment    => 'Hospedagem',
            self::Cloud          => 'Cloud',
            self::ContractStatus => 'Contrato',
            self::SupportType    => 'Suporte',
            self::Criticality    => 'Criticidade',
        };
    }

    /**
     * Só `Hospedagem`/`Cloud` expõem um ícone (heroicons) por valor — usados
     * como destaque discreto em cima de cada bloco de solução no data-viz
     * (F3, `integration-viz.js`). Os demais grupos não têm campo de ícone na
     * UI de "Gerenciar atributos".
     */
    public function supportsIcon(): bool
    {
        return match ($this) {
            self::Environment, self::Cloud => true,
            default                        => false,
        };
    }
}
