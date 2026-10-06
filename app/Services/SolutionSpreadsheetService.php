<?php

namespace App\Services;

use App\Enums\PersonSolutionRole;
use App\Enums\SpreadsheetAudience;
use App\Models\AttributeOption;
use App\Models\Person;
use App\Models\Solution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The solutions spreadsheet's dataset: one row per solution, one column per
 * fact the catalog holds about it — attributes, owners by role, documentation.
 *
 * ONE builder for the three places that read it — the inventory screen, the
 * magic link and the Excel/CSV export — so a column cannot exist on screen and
 * be missing from the file, or the other way round.
 *
 * Filtering, sorting and hiding columns happen in the browser
 * (`solutions-sheet.js`): the catalog is around a hundred rows, small enough to
 * ship whole. The export therefore does not re-implement any of it — the page
 * sends the ids it is showing, in the order it shows them, and the columns that
 * are visible, and the file is built from exactly that.
 *
 * A cell is a string, a list of strings (several owners, several cadernos — a
 * value filter matches a row when ANY of them is selected), an int, or null.
 */
class SolutionSpreadsheetService
{
    /** Attribute columns, in reading order: key => [AttributeOption group, label]. */
    private const ATTRIBUTES = [
        'category'        => ['category', 'Categoria'],
        'status'          => ['status', 'Status'],
        'directorate'     => ['directorate', 'Diretoria'],
        'environment'     => ['environment', 'Hospedagem'],
        'cloud'           => ['cloud', 'Cloud'],
        'contract_status' => ['contract_status', 'Contrato'],
        'criticality'     => ['criticality', 'Criticidade'],
        // Not "Suporte", the attribute's own label: the owners group below has
        // a "Suporte" column of people, and a file with two columns of the same
        // name is unreadable once it leaves this screen.
        'support_type' => ['support_type', 'Tipo de suporte'],
    ];

    /**
     * Columns that come from the DOCUMENTATION module (cadernos, diagrams).
     * Dropped — not merely hidden — for a reader whose level there is None
     * (App\Enums\AccessLevel): the spreadsheet is the catalog's screen, and it
     * must not become a way to read the other module.
     */
    public const DOCUMENTATION_COLUMNS = ['notebooks', 'documented', 'diagrams'];

    /** Owner roles that start hidden — the rarer ones, to keep the first screen scannable. */
    private const HIDDEN_ROLES = [PersonSolutionRole::KeyUser, PersonSolutionRole::VendorContact];

    /**
     * Column definitions for `$audience`, in display order.
     *
     * @return list<array{key: string, label: string, group: string, kind: string, hidden: bool}>
     */
    public function columns(SpreadsheetAudience $audience, bool $documentation = true): array
    {
        $columns = [
            $this->column('name', 'Solução', 'Solução'),
            $this->column('description', 'Descrição', 'Solução', kind: 'long'),
            $this->column('vendor', 'Fornecedor', 'Solução'),
        ];

        foreach (self::ATTRIBUTES as $key => [, $label]) {
            $columns[] = $this->column($key, $label, 'Classificação', hidden: $key === 'cloud');
        }

        $columns[] = $this->column('support_operation_note', 'Nota de suporte e operação', 'Classificação', kind: 'long', hidden: true);

        foreach (PersonSolutionRole::cases() as $role) {
            $columns[] = $this->column(
                'role_' . $role->value,
                $role->label(),
                'Responsáveis',
                hidden: in_array($role, self::HIDDEN_ROLES, true),
            );
        }

        // Withheld from the magic link, not merely hidden: a hidden column is
        // still in the page source and one click away from the export.
        if ($audience === SpreadsheetAudience::Internal) {
            $columns[] = $this->column('contacts', 'Contatos dos responsáveis', 'Responsáveis', kind: 'long', hidden: true);
        }

        $columns[] = $this->column('notebooks', 'Cadernos', 'Documentação');
        $columns[] = $this->column('documented', 'Documentada', 'Documentação');
        $columns[] = $this->column('diagrams', 'Diagramas', 'Documentação', kind: 'number');
        $columns[] = $this->column('updated_at', 'Atualizado em', 'Documentação', kind: 'date', hidden: true);

        if (! $documentation) {
            $columns = array_values(array_filter($columns, fn (array $c) => ! in_array($c['key'], self::DOCUMENTATION_COLUMNS, true)));
        }

        return $columns;
    }

    /**
     * Every row for `$audience`, ordered by name — or, when `$ids` is given,
     * only those rows and in THAT order (the order the screen was showing).
     *
     * @param  list<int>|null  $ids
     * @return list<array{id: int, url: string|null, cells: array<string, mixed>}>
     */
    public function rows(SpreadsheetAudience $audience, ?array $ids = null, bool $documentation = true): array
    {
        $solutions = $this->query()
            ->when($ids !== null, fn (Builder $q) => $q->whereKey($ids))
            ->get();

        if ($ids !== null) {
            $position = array_flip(array_values($ids));
            $solutions = $solutions->sortBy(fn (Solution $s) => $position[$s->id] ?? PHP_INT_MAX)->values();
        }

        $internal = $audience === SpreadsheetAudience::Internal;

        return $solutions->map(fn (Solution $solution) => [
            'id'    => $solution->id,
            'url'   => $internal ? route('solutions.show', $solution) : null,
            'cells' => $documentation
                ? $this->cells($solution, $internal)
                : array_diff_key($this->cells($solution, $internal), array_flip(self::DOCUMENTATION_COLUMNS)),
        ])->all();
    }

    /**
     * What the screen embeds: the columns and every row.
     *
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    public function payload(SpreadsheetAudience $audience, bool $documentation = true): array
    {
        return [
            'columns' => $this->columns($audience, $documentation),
            'rows'    => $this->rows($audience, documentation: $documentation),
        ];
    }

    /** @return Builder<Solution> */
    private function query(): Builder
    {
        return Solution::query()
            ->select([
                'solutions.id', 'solutions.name', 'solutions.slug', 'solutions.description',
                'solutions.vendor_company_id', 'solutions.category', 'solutions.directorate',
                'solutions.support_type', 'solutions.environment', 'solutions.cloud',
                'solutions.contract_status', 'solutions.support_operation_note',
                'solutions.criticality', 'solutions.status', 'solutions.updated_at',
            ])
            ->with([
                'vendor:id,name',
                // Primary owner first within each role, then alphabetical.
                'people' => fn ($q) => $q
                    ->select(['people.id', 'people.name', 'people.email', 'people.phone'])
                    ->orderByDesc('person_solution.is_primary')
                    ->orderBy('people.name'),
                'notebooks' => fn ($q) => $q->select(['notebooks.id', 'notebooks.name']),
            ])
            ->withCount('diagrams')
            // "Documentada" means the same thing as the catalog's "sem
            // documentação" filter, negated: some linked caderno has a page
            // with content (Solution::scopeFilter).
            ->withExists(['notebooks as is_documented' => fn (Builder $q) => $q->whereHas('documentedPages')])
            ->orderBy('solutions.name');
    }

    /** @return array<string, mixed> */
    private function cells(Solution $solution, bool $internal): array
    {
        $cells = [
            'name'        => $solution->name,
            'description' => $this->text($solution->description),
            'vendor'      => $solution->vendor?->name,
        ];

        foreach (self::ATTRIBUTES as $key => [$group]) {
            $value = $solution->{$key};
            // A value whose option was since deleted keeps showing what is
            // stored rather than turning into a blank nobody can filter for.
            $cells[$key] = $value === null ? null : (AttributeOption::labelFor($group, $value) ?? $value);
        }

        $cells['support_operation_note'] = $this->text($solution->support_operation_note);

        $byRole = $solution->people->groupBy(fn (Person $person) => $person->pivot->role);

        foreach (PersonSolutionRole::cases() as $role) {
            $cells['role_' . $role->value] = $this->names($byRole->get($role->value, collect()));
        }

        if ($internal) {
            $cells['contacts'] = $solution->people
                ->unique('id')
                ->map(function (Person $person): ?string {
                    $contact = collect([$person->email, $person->phone])->filter()->implode(' · ');

                    return $contact === '' ? null : $person->name . ' — ' . $contact;
                })
                ->filter()
                ->values()
                ->all();
        }

        $cells['notebooks'] = $solution->notebooks->pluck('name')->all();
        $cells['documented'] = $solution->is_documented ? 'Sim' : 'Não';
        $cells['diagrams'] = (int) $solution->diagrams_count;
        $cells['updated_at'] = $solution->updated_at?->toDateString();

        return $cells;
    }

    /** @param  Collection<int, Person>  $people */
    private function names(Collection $people): array
    {
        return $people->pluck('name')->unique()->values()->all();
    }

    private function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return array{key: string, label: string, group: string, kind: string, hidden: bool} */
    private function column(string $key, string $label, string $group, string $kind = 'text', bool $hidden = false): array
    {
        return compact('key', 'label', 'group', 'kind', 'hidden');
    }
}
