<?php

namespace App\Enums;

/**
 * The 8 Solution attributes (and, for `Criticality`, also Diagram)
 * whose values are manageable at runtime via `AttributeOption` — see
 * `App\Http\Controllers\AttributeOptionController`. Adding a new group is
 * a code change; the *values* inside each group are editable data.
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
     * Only `Environment`/`Cloud` expose an icon (heroicons) per value — used
     * as a subtle highlight on top of each solution block in the data-viz
     * (F3, `chain-viz.js`). The other groups have no icon field in the
     * "Manage attributes" UI.
     */
    public function supportsIcon(): bool
    {
        return match ($this) {
            self::Environment, self::Cloud => true,
            default                        => false,
        };
    }
}
