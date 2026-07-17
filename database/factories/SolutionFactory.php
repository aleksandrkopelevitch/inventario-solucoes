<?php

namespace Database\Factories;

use App\Models\Solution;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Solution>
 */
class SolutionFactory extends Factory
{
    protected $model = Solution::class;

    /**
     * Fixed values (not read from `AttributeOption`, on purpose — the factory
     * shouldn't depend on `attribute_options` being seeded in the test database).
     */
    private const CATEGORIES = [
        'erp', 'ipaas', 'data_bi', 'ecommerce', 'manufacturing', 'tms', 'payments',
        'tax', 'security', 'iam', 'hcm', 'marketing', 'crm', 'itsm',
        'customer_service', 'legal_grc', 'infrastructure', 'internal_platform', 'other',
    ];

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name'                   => $name,
            'slug'                   => Str::slug($name) . '-' . Str::lower(Str::random(4)),
            'description'            => fake()->sentence(),
            'vendor_company_id'      => null,
            'category'               => fake()->randomElement(self::CATEGORIES),
            'directorate'            => fake()->randomElement(['TI', 'Financeiro', 'Comercial', 'Logística', null]),
            'support_type'           => 'third_party',
            'environment'            => 'saas',
            'cloud'                  => null,
            'contract_status'        => 'unknown',
            'support_operation_note' => null,
            'criticality'            => null,
            'status'                 => 'active',
            'logo_path'              => null,
        ];
    }

    public function planned(): static
    {
        return $this->state(fn () => ['status' => 'planned']);
    }

    /** Solution with documentation filled in (real content in the `documentation` column). */
    public function fullyDocumented(): static
    {
        return $this->state(fn () => [
            'documentation' => "# Documentação\n\nConteúdo de exemplo.",
        ]);
    }
}
