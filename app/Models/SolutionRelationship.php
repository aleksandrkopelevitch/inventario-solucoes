<?php

namespace App\Models;

use App\Enums\ManualRelationshipDirection;
use App\Enums\RelationshipDirection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An interconnection between two catalog solutions that a person recorded
 * (the solution page's "Relacionamentos" card, or the MCP's
 * `set_solution_relationship`). One per pair, stored normalized — A is the
 * lower id, `direction` is read from A — and it wins over whatever the
 * documentation's UML diagrams say about the same pair. See the migration and
 * `.claude/rules/solution-relationships.md`.
 *
 * Who recorded it and who changed it last are written by `booted()`, never
 * from a payload — the same rule a documentation page follows.
 */
class SolutionRelationship extends Model
{
    protected $fillable = [
        'solution_a_id',
        'solution_b_id',
        'direction',
    ];

    protected function casts(): array
    {
        return [
            'solution_a_id' => 'integer',
            'solution_b_id' => 'integer',
            'direction'     => ManualRelationshipDirection::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $relationship) => $relationship->created_by_id ??= auth()->id());

        static::saving(function (self $relationship) {
            if ($relationship->isDirty()) {
                $relationship->updated_by_id = auth()->id();
            }
        });
    }

    public function solutionA(): BelongsTo
    {
        return $this->belongsTo(Solution::class, 'solution_a_id');
    }

    public function solutionB(): BelongsTo
    {
        return $this->belongsTo(Solution::class, 'solution_b_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    /** The records that have `$solution` on either side. */
    public function scopeInvolving(Builder $query, Solution|int $solution): void
    {
        $id = $solution instanceof Solution ? $solution->getKey() : $solution;

        $query->where(fn (Builder $q) => $q->where('solution_a_id', $id)->orWhere('solution_b_id', $id));
    }

    /** The record for the pair {`$one`, `$two`}, whichever order they come in. */
    public function scopeForPair(Builder $query, int $one, int $two): void
    {
        $query->where('solution_a_id', min($one, $two))->where('solution_b_id', max($one, $two));
    }

    /** The other solution's id, seen from `$solution`. */
    public function otherId(Solution|int $solution): int
    {
        $id = $solution instanceof Solution ? $solution->getKey() : $solution;

        return $this->solution_a_id === $id ? $this->solution_b_id : $this->solution_a_id;
    }

    /** Which way it runs, seen from `$solution`. */
    public function directionFrom(Solution|int $solution): RelationshipDirection
    {
        $id = $solution instanceof Solution ? $solution->getKey() : $solution;

        return $this->direction->asSeenFrom($this->solution_a_id === $id);
    }
}
