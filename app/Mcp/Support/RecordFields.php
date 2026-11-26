<?php

namespace App\Mcp\Support;

use App\Enums\ManualRelationshipDirection;
use App\Enums\RelationshipDirection;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Person;
use App\Models\Solution;
use App\Models\SolutionRelationship;
use App\Services\DocumentationPageService;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads and writes a record's FIELDS the way the write log stores them —
 * the one place that knows a field can be a column or a whole relation.
 *
 * A column is its raw stored value (`category` is `erp`, not "ERP"; a foreign
 * key is the id), because what a revert puts back has to be exactly what was
 * there. A relation is its whole set, in a stable order, keyed by SLUG — so the
 * log of a link reads as a list of people rather than of row ids, and a revert
 * re-syncs the set instead of guessing which single row to undo.
 *
 * `same()` compares through `normalize()`: a value read back from the database
 * may come as `"12"` where it was written as `12`, and a revert that refused
 * because of that would be refusing over the driver, not over a later edit.
 */
final class RecordFields
{
    /** Relation fields, per model: what `get()`/`set()` treat as a set rather than a column. */
    private const RELATIONS = [
        Solution::class          => ['people', 'relationships'],
        Notebook::class          => ['solutions'],
        DocumentationPage::class => ['placement'],
    ];

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public static function snapshot(Model $model, array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $values[$field] = self::get($model, $field);
        }

        return $values;
    }

    public static function get(Model $model, string $field): mixed
    {
        if (self::isRelation($model, $field)) {
            return match (true) {
                $model instanceof Solution && $field === 'people' => $model->people()
                    ->orderBy('people.slug')
                    ->get(['people.id', 'people.slug'])
                    ->map(fn (Person $person) => [
                        'person' => $person->slug,
                        'role'   => $person->pivot->role,
                        // Kept with the link: both controllers carry it over
                        // when a link is re-pointed, and a revert must too.
                        'is_primary' => (bool) $person->pivot->is_primary,
                    ])
                    ->values()
                    ->all(),
                // The MANUAL interconnections only (the documented ones are
                // the documentation's, and change by editing a page), each
                // read from this solution's side, keyed by the other's slug.
                $model instanceof Solution && $field === 'relationships' => SolutionRelationship::query()
                    ->involving($model)
                    ->with(['solutionA:id,slug', 'solutionB:id,slug'])
                    ->get()
                    ->map(fn (SolutionRelationship $r) => [
                        'solution'  => ($r->solution_a_id === $model->getKey() ? $r->solutionB : $r->solutionA)->slug,
                        'direction' => $r->directionFrom($model)->value,
                    ])
                    ->sortBy('solution')
                    ->values()
                    ->all(),
                $model instanceof Notebook && $field === 'solutions' => $model->solutions()
                    ->reorder('solutions.slug')
                    ->pluck('solutions.slug')
                    ->all(),
                // Where the page sits in its caderno's tree: under which page
                // (by slug — the id means nothing on any screen) and at which
                // position among its siblings.
                $model instanceof DocumentationPage && $field === 'placement' => [
                    'parent'   => $model->parent()->value('slug'),
                    'position' => app(DocumentationPageService::class)->indexAmongSiblings($model),
                ],
            };
        }

        return $model->getAttributes()[$field] ?? null;
    }

    /**
     * Puts `$values` back. Columns are filled and saved once; a relation is
     * re-synced. A person a stored set names who no longer exists is skipped —
     * there is nothing to link, and refusing the whole revert over it would
     * leave every other field wrong too.
     *
     * @param  array<string, mixed>  $values
     */
    public static function restore(Model $model, array $values): void
    {
        $columns = [];

        foreach ($values as $field => $value) {
            if (! self::isRelation($model, $field)) {
                $columns[$field] = $value;

                continue;
            }

            if ($model instanceof Solution && $field === 'people') {
                $ids = Person::query()
                    ->whereIn('slug', collect($value)->pluck('person')->all())
                    ->pluck('id', 'slug');

                $model->people()->sync(collect($value)
                    ->filter(fn (array $link) => isset($ids[$link['person']]))
                    ->mapWithKeys(fn (array $link) => [$ids[$link['person']] => [
                        'role'       => $link['role'],
                        'is_primary' => (bool) ($link['is_primary'] ?? false),
                    ]])
                    ->all());
            }

            // Re-syncs this solution's manual interconnections to the set: a
            // record not in it is removed, one in it is written back with its
            // direction. A counterpart that no longer exists is skipped.
            if ($model instanceof Solution && $field === 'relationships') {
                $ids = Solution::query()->whereIn('slug', collect($value)->pluck('solution')->all())->pluck('id', 'slug');
                $keep = [];

                foreach ($value as $link) {
                    if (! isset($ids[$link['solution']])) {
                        continue;
                    }

                    $other = (int) $ids[$link['solution']];
                    $a = min($model->getKey(), $other);
                    $record = SolutionRelationship::query()->forPair($model->getKey(), $other)->first() ?? new SolutionRelationship;
                    $record->fill([
                        'solution_a_id' => $a,
                        'solution_b_id' => max($model->getKey(), $other),
                        'direction'     => ManualRelationshipDirection::fromSide(RelationshipDirection::from($link['direction']), $model->getKey() === $a),
                    ])->save();
                    $keep[] = $record->getKey();
                }

                SolutionRelationship::query()->involving($model)->whereKeyNot($keep)->get()->each->delete();
            }

            if ($model instanceof Notebook && $field === 'solutions') {
                $model->solutions()->sync(Solution::query()->whereIn('slug', (array) $value)->pluck('id')->all());
            }

            if ($model instanceof DocumentationPage && $field === 'placement') {
                $parent = $value['parent'] === null
                    ? null
                    : $model->notebook()->first()?->pages()->where('slug', $value['parent'])->first();

                // A parent that is gone (or that can no longer hold the page)
                // leaves the page where it is rather than half-moving it.
                if ($value['parent'] === null || $parent) {
                    app(DocumentationPageService::class)->place($model, $parent, (int) $value['position']);
                }
            }
        }

        if ($columns !== []) {
            $model->forceFill($columns)->save();
        }
    }

    /**
     * Only the fields whose value differs between the two snapshots — what a
     * write log row keeps.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    public static function changed(array $before, array $after): array
    {
        return array_values(array_filter(
            array_keys($after),
            fn (string $field) => ! self::same($before[$field] ?? null, $after[$field]),
        ));
    }

    public static function same(mixed $a, mixed $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::normalize(...), $value);
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return $value === null || $value === '' ? null : (is_bool($value) ? (int) $value : (string) $value);
    }

    private static function isRelation(Model $model, string $field): bool
    {
        return in_array($field, self::RELATIONS[$model::class] ?? [], true);
    }
}
