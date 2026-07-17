<?php

namespace Database\Seeders;

use App\Models\AttributeOption;
use Illuminate\Database\Seeder;

/**
 * Populates `attribute_options` — the values/labels for the 8 attributes now
 * manageable via the "Manage attributes" area (previously, 7 of them were
 * fixed PHP `enum`s; see history in `app/Enums/{Category,SolutionStatus,...}.php`
 * before they were removed). Idempotent: `updateOrCreate` by `[group, value]`.
 */
class AttributeOptionSeeder extends Seeder
{
    /** @var array<string, array<string, string|array{label: string, icon: string}>> */
    private const FIXED_GROUPS = [
        'category' => [
            'erp'               => 'ERP',
            'ipaas'             => 'iPaaS',
            'data_bi'           => 'Dados e BI',
            'ecommerce'         => 'E-commerce',
            'manufacturing'     => 'Fábrica e produção',
            'tms'               => 'Logística e TMS',
            'payments'          => 'Pagamentos e crédito',
            'tax'               => 'Fiscal',
            'security'          => 'Segurança',
            'iam'               => 'Identidade e acesso',
            'hcm'               => 'Gente e RH',
            'marketing'         => 'Marketing',
            'crm'               => 'CRM',
            'itsm'              => 'ITSM',
            'customer_service'  => 'Atendimento',
            'legal_grc'         => 'Jurídico, GRC e LGPD',
            'infrastructure'    => 'Infraestrutura',
            'internal_platform' => 'Plataforma interna Leo',
            'other'             => 'Outros',
        ],
        'status' => [
            'active'     => 'Ativo',
            'evaluating' => 'Em avaliação',
            'deprecated' => 'Descontinuado',
            'planned'    => 'Planejado',
        ],
        // `environment`/`cloud` are the only groups with an icon (heroicons
        // outline) — see `AttributeGroup::supportsIcon()` — that's why they
        // come as ['label', 'icon'] instead of a plain string.
        'environment' => [
            'saas'          => ['label' => 'SaaS', 'icon' => 'cloud'],
            'saas_internal' => ['label' => 'SaaS interno', 'icon' => 'server-stack'],
            'on_premise'    => ['label' => 'On-Premises', 'icon' => 'building-office-2'],
        ],
        'cloud' => [
            'azure'  => ['label' => 'Azure', 'icon' => 'cloud'],
            'gcp'    => ['label' => 'GCP', 'icon' => 'cloud'],
            'aws'    => ['label' => 'AWS', 'icon' => 'cloud'],
            'oracle' => ['label' => 'Oracle Cloud', 'icon' => 'cloud'],
        ],
        'contract_status' => [
            'contracted'     => 'Contratado',
            'not_contracted' => 'Sem contrato',
            'card'           => 'Cartão',
            'unknown'        => 'Sem informação',
        ],
        'support_type' => [
            'internal'    => 'Interno',
            'third_party' => 'Terceiro',
            'hybrid'      => 'Híbrido',
        ],
        'criticality' => [
            'low'      => 'Baixa',
            'medium'   => 'Média',
            'high'     => 'Alta',
            'critical' => 'Crítica',
        ],
    ];

    public function run(): void
    {
        foreach (self::FIXED_GROUPS as $group => $options) {
            foreach ($options as $value => $meta) {
                AttributeOption::updateOrCreate(
                    ['group' => $group, 'value' => $value],
                    is_array($meta) ? $meta : ['label' => $meta],
                );
            }
        }

        $this->seedDirectorates();
    }

    /**
     * Derives the Directorate options directly from the inventory (same
     * source as `SolutionSeeder`), preserving the existing values exactly as
     * they are today — including combined ones like "Digital / Comercial".
     * `value` is the raw text itself (no slug): `solutions.directorate`
     * already stores this raw text today, so the option needs to match it
     * exactly for `Rule::exists` to keep validating existing records. Reads
     * the JSON instead of the `solutions` table so it doesn't depend on
     * execution order between this seeder and `SolutionSeeder`.
     */
    private function seedDirectorates(): void
    {
        $path = database_path('data/inventory_seed.json');
        $records = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        collect($records)
            ->pluck('directorate')
            ->filter(fn (?string $directorate) => filled($directorate))
            ->unique()
            ->each(fn (string $directorate) => AttributeOption::updateOrCreate(
                ['group' => 'directorate', 'value' => $directorate],
                ['label' => $directorate],
            ));
    }
}
