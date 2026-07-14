<?php

namespace Database\Seeders;

use App\Models\AttributeOption;
use Illuminate\Database\Seeder;

/**
 * Popula `attribute_options` — os valores/rótulos dos 8 atributos hoje
 * gerenciáveis via a área "Gerenciar atributos" (antes, 7 deles eram `enum`s
 * PHP fixos; ver histórico em `app/Enums/{Category,SolutionStatus,...}.php`
 * antes de removidos). Idempotente: `updateOrCreate` por `[group, value]`.
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
        // `environment`/`cloud` são os únicos grupos com ícone (heroicons
        // outline) — ver `AttributeGroup::supportsIcon()` — por isso vêm como
        // ['label', 'icon'] em vez de string simples.
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
     * Deriva as opções de Diretoria diretamente do inventário (mesma fonte
     * do `SolutionSeeder`), preservando os valores existentes tal como
     * estão hoje — inclusive combinados como "Digital / Comercial". `value`
     * é o próprio texto (sem slug): `solutions.directorate` já guarda esse
     * texto cru hoje, então a opção precisa bater exatamente com ele para
     * `Rule::exists` continuar validando os registros existentes. Lê o JSON
     * em vez da tabela `solutions` para não depender da ordem de execução
     * entre este seeder e o `SolutionSeeder`.
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
