<?php

namespace App\Services;

use App\Models\AttributeOption;
use App\Models\Integration;
use App\Models\Solution;

/**
 * Global catalog counters for the Soluções index hero (stat-strip) — total
 * solutions by status plus the total integration count. Deliberately
 * independent of any active filter, same as `DocumentationCoverageService`'s
 * counters: this is the "whole inventory at a glance" summary, not a
 * reflection of the filtered grid below it.
 */
class SolutionCatalogStatsService
{
    /**
     * @return array{
     *     total: int,
     *     byStatus: array<int, array{value: string, label: string, count: int}>,
     *     integrations: int,
     * }
     */
    public function summary(): array
    {
        $counts = Solution::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $byStatus = AttributeOption::options('status')
            ->map(fn ($option) => [
                'value' => $option->value,
                'label' => $option->label,
                'count' => (int) ($counts[$option->value] ?? 0),
            ])
            ->filter(fn (array $row) => $row['count'] > 0)
            ->values()
            ->all();

        return [
            'total'        => $counts->sum(),
            'byStatus'     => $byStatus,
            'integrations' => Integration::query()->count(),
        ];
    }
}
