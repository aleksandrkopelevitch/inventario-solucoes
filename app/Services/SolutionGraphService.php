<?php

namespace App\Services;

use App\Enums\PersonSolutionRole;
use App\Models\AttributeOption;
use App\Models\Person;
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
            // Only what THIS axis reads. `solutionNode()` touches no relation
            // at all — every field it builds is a column or a `*_label`
            // accessor — so the two loads below exist purely for `bucket()`,
            // and loading both on all four axes hydrated a Person plus a pivot
            // model per owner link on the reading that opens the screen.
            ->when($axis === 'company', fn (Builder $q) => $q->with('vendor:id,name'))
            ->when($axis === 'owner', fn (Builder $q) => $q->with([
                // Both pivot columns are read by `responsibleFor()`: a solution
                // belongs to exactly one hub, so a block is never drawn twice,
                // and WHICH one cannot be left to the database's row order.
                'people' => fn ($p) => $p->select('people.id', 'people.name')->withPivot(['is_primary', 'role']),
            ]))
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
     * Roles that can answer FOR a solution, best first.
     *
     * `VendorContact` is deliberately absent: that person works for the
     * supplier, and a screen titled "Responsável" naming them would say
     * something false about who to ask inside Leo. A solution whose only links
     * are vendor contacts belongs under "Sem responsável", which is the true
     * answer.
     */
    private const RESPONSIBLE_ROLES = [
        PersonSolutionRole::Manager,
        PersonSolutionRole::Business,
        PersonSolutionRole::Technical,
        PersonSolutionRole::KeyUser,
        PersonSolutionRole::Support,
    ];

    /**
     * The one person a solution is filed under on the owner axis.
     *
     * `is_primary` alone could not decide it. `SolutionController::attachPerson`
     * never writes the flag — the only writer is the seeder, once per role — so
     * the old pick fell through to `people->first()` on a relation with no
     * ordering, which is whichever row the database happened to return: a
     * solution could change hubs between two page loads, and the ambiguous case
     * is the normal one here (78 of the 109 solutions in the dev catalog carry
     * more than one link).
     *
     * Three steps, each total, so the same catalog always draws the same map: a
     * primary link, then the role that best answers for a system, then the
     * lowest person id.
     */
    private function responsibleFor(Solution $solution): ?Person
    {
        $rank = array_map(fn (PersonSolutionRole $role) => $role->value, self::RESPONSIBLE_ROLES);

        // Only the vendor contact is EXCLUDED; a role this list does not know
        // still answers, ranked last. Filtering to the known list instead would
        // make a role added to the enum later disappear from the map silently,
        // which is the failure this whole method exists to stop.
        $order = fn (Person $person) => ($i = array_search($person->pivot->role, $rank, true)) === false
            ? count($rank)
            : $i;

        return $solution->people
            ->reject(fn (Person $person) => $person->pivot->role === PersonSolutionRole::VendorContact->value)
            ->sortBy([
                fn (Person $a, Person $b) => ($b->pivot->is_primary ? 1 : 0) <=> ($a->pivot->is_primary ? 1 : 0),
                fn (Person $a, Person $b) => $order($a) <=> $order($b),
                fn (Person $a, Person $b) => $a->id <=> $b->id,
            ])
            ->first();
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
                $person = $this->responsibleFor($solution);

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
