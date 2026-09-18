<?php

namespace App\Services;

use App\Models\AttributeOption;
use App\Models\Solution;
use App\Support\CategoryPalette;
use Illuminate\Database\Eloquent\Builder;

/**
 * The catalog read as a GROUPING instead of as a topology.
 *
 * The ecosystem map answers "who talks to whom". This answers the questions
 * asked next to it and which the map cannot show: how many systems each Leo
 * directorate owns, who is responsible for what, which vendor the catalog
 * depends on most. Same solutions, same blocks, different question — so it
 * returns the SAME contract `DiagramGraphService` returns, with one field
 * added (`groups`) and the two the drill-down needs left empty. The renderer
 * then draws hubs and spokes with the code it already had, instead of the
 * screen being a second map that merely looks like the first.
 */
class SolutionGraphService
{
    /** Every axis the view offers, in the order the select lists them. */
    public const AXES = [
        'directorate' => 'Diretoria',
        'owner'       => 'Responsável',
        'company'     => 'Fornecedor',
        'category'    => 'Categoria',
    ];

    /**
     * The 8 families `CategoryPalette` uses, so a hub on an axis that has no
     * colour of its own (a directorate, a person) still gets a stable one.
     */
    private const FAMILIES = ['emerald', 'teal', 'blue', 'indigo', 'fuchsia', 'rose', 'amber', 'slate'];

    public function __construct(private readonly DiagramGraphService $graph) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function groupedBy(string $axis, array $filters = []): array
    {
        $axis = array_key_exists($axis, self::AXES) ? $axis : 'directorate';

        $solutions = Solution::query()
            ->when(
                ($filters['status'] ?? null) && $filters['status'] !== 'all',
                fn (Builder $q) => $q->where('status', $filters['status'])
            )
            ->when($filters['category'] ?? null, fn (Builder $q) => $q->where('category', $filters['category']))
            ->when($filters['directorate'] ?? null, fn (Builder $q) => $q->where('directorate', $filters['directorate']))
            ->with([
                'vendor:id,name',
                // The owners grid stores who is primary on the pivot; the hub
                // uses that one, so a solution belongs to exactly one person
                // and a block is never drawn twice.
                'people' => fn ($q) => $q->select('people.id', 'people.name')->withPivot('is_primary'),
            ])
            ->orderBy('name')
            ->get();

        $nodes = [];
        $groups = [];

        foreach ($solutions as $solution) {
            [$key, $label] = $this->bucket($axis, $solution);
            $node = $this->graph->solutionNode($solution);
            $nodes[$node['id']] = $node;

            $groups[$key] ??= [
                'id'        => 'grp-' . $key,
                'label'     => $label,
                'axis'      => $axis,
                'solutions' => [],
            ];
            $groups[$key]['solutions'][] = $node['id'];
        }

        // Biggest first: the reading everybody wants from this screen is which
        // bucket is heaviest, and a ring laid out in that order says it before
        // anybody counts.
        $groups = collect($groups)
            ->sortByDesc(fn (array $group) => count($group['solutions']))
            ->values()
            ->map(fn (array $group, int $i) => $group + [
                'count'  => count($group['solutions']),
                'family' => $axis === 'category'
                    ? CategoryPalette::family($this->categoryOf($group, $nodes))
                    : self::FAMILIES[$i % count(self::FAMILIES)],
            ])
            ->all();

        return [
            'nodes'     => array_values($nodes),
            'edges'     => [],
            'diagrams'  => [],
            'groups'    => $groups,
            'groupAxis' => $axis,
        ];
    }

    /**
     * Which bucket a solution falls in, and what that bucket is called.
     *
     * A blank value is a bucket of its own rather than a dropped row: "12
     * systems with no owner" is the most useful thing this screen can say, and
     * hiding them would make the totals lie.
     *
     * @return array{0: string, 1: string}
     */
    private function bucket(string $axis, Solution $solution): array
    {
        return match ($axis) {
            'owner' => (function () use ($solution) {
                $person = $solution->people->firstWhere('pivot.is_primary', true) ?? $solution->people->first();

                return $person ? ['owner-' . $person->id, $person->name] : ['owner-none', 'Sem responsável'];
            })(),
            'company' => $solution->vendor
                ? ['company-' . $solution->vendor->id, $solution->vendor->name]
                : ['company-none', 'Sem fornecedor'],
            'category' => $solution->category
                ? ['category-' . $solution->category, AttributeOption::labelFor('category', $solution->category) ?? $solution->category]
                : ['category-none', 'Sem categoria'],
            default => $solution->directorate
                ? ['directorate-' . $solution->directorate, AttributeOption::labelFor('directorate', $solution->directorate) ?? $solution->directorate]
                : ['directorate-none', 'Sem diretoria'],
        };
    }

    /**
     * On the category axis the hub takes the colour its own members already
     * have everywhere else in the app, instead of a colour from the rotation.
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, array<string, mixed>>  $nodes
     */
    private function categoryOf(array $group, array $nodes): ?string
    {
        $first = $nodes[$group['solutions'][0] ?? ''] ?? null;

        return $first['category'] ?? null;
    }
}
