<?php

namespace App\Models;

use Database\Factories\SolutionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Solution extends Model
{
    /** @use HasFactory<SolutionFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'vendor_company_id',
        'category',
        'directorate',
        'support_type',
        'environment',
        'cloud',
        'contract_status',
        'support_operation_note',
        'criticality',
        'status',
        'logo_path',
        'map_position',
    ];

    protected function casts(): array
    {
        return [
            'map_position' => 'array',
        ];
    }

    /**
     * Attribute labels now managed via `AttributeOption` (the "Manage
     * attributes" area) — the columns themselves only store the `value`
     * (string); the display `label` comes from an in-memory (cached) lookup,
     * never a query per solution (avoids N+1 in the catalog cards).
     */
    protected function categoryLabel(): Attribute
    {
        return Attribute::get(fn () => AttributeOption::labelFor('category', $this->category));
    }

    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn () => AttributeOption::labelFor('status', $this->status));
    }

    protected function environmentLabel(): Attribute
    {
        return Attribute::get(fn () => AttributeOption::labelFor('environment', $this->environment));
    }

    protected function environmentIcon(): Attribute
    {
        return Attribute::get(fn () => AttributeOption::iconFor('environment', $this->environment));
    }

    protected function cloudLabel(): Attribute
    {
        return Attribute::get(fn () => AttributeOption::labelFor('cloud', $this->cloud));
    }

    protected function cloudIcon(): Attribute
    {
        return Attribute::get(fn () => AttributeOption::iconFor('cloud', $this->cloud));
    }

    protected function contractStatusLabel(): Attribute
    {
        return Attribute::get(fn () => AttributeOption::labelFor('contract_status', $this->contract_status));
    }

    protected function supportTypeLabel(): Attribute
    {
        return Attribute::get(fn () => AttributeOption::labelFor('support_type', $this->support_type));
    }

    protected function criticalityLabel(): Attribute
    {
        return Attribute::get(fn () => AttributeOption::labelFor('criticality', $this->criticality));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'vendor_company_id');
    }

    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class)
            ->withPivot(['role', 'is_primary'])
            ->withTimestamps();
    }

    public function diagramsAsSource(): HasMany
    {
        return $this->hasMany(Diagram::class, 'source_solution_id');
    }

    public function diagramsAsTarget(): HasMany
    {
        return $this->hasMany(Diagram::class, 'target_solution_id');
    }

    /**
     * Topologies a committee approved for this solution that the catalog has
     * not caught up with yet.
     *
     * Surfaced on the solution's own page because that is where someone reads
     * the topology and trusts it. An approved change nobody applied is drift,
     * and drift that is invisible is the failure the CATI module exists to
     * prevent — see `App\Models\ApprovedTopology`.
     */
    public function pendingTopologies(): HasMany
    {
        return $this->hasMany(ApprovedTopology::class)->pending();
    }

    /** Every diagram this solution participates in, in any role. */
    public function diagrams(): BelongsToMany
    {
        return $this->belongsToMany(Diagram::class, 'diagram_solution')
            ->withPivot(['position'])
            ->withTimestamps();
    }

    /**
     * The cadernos that document this solution.
     *
     * A Solution used to OWN a page tree of its own (`pages()`, a morphMany).
     * It doesn't any more, and the difference is not cosmetic: documentation is
     * written once, in a notebook, and read from every solution that notebook
     * describes. Anything asking "is this solution documented?" goes through
     * here — `whereHas('notebooks.documentedPages')` — never through a
     * relation of its own.
     */
    public function notebooks(): BelongsToMany
    {
        return $this->belongsToMany(Notebook::class)->orderBy('name');
    }

    public function scopeWithDiagramCounts(Builder $query): void
    {
        $query->withCount([
            'diagramsAsSource as active_out' => fn (Builder $q) => $q->where('status', 'active'),
            'diagramsAsTarget as active_in'  => fn (Builder $q) => $q->where('status', 'active'),
        ]);
    }

    /**
     * Catalog (F1) filters — search by name/vendor/owner and filters by
     * category, directorate, hosting, contract, status and "no
     * documentation". Reused by Solutions\Index (list) and
     * Solutions\ResultsCount (counter), so the two never diverge.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['search'] ?? null, fn (Builder $q, $search) => $q->where(fn (Builder $w) => $w
                // Qualified with the table name: `Solutions\Index` conditionally
                // left-joins `companies as vendor` (sort by vendor column), and an
                // unqualified `name` would become ambiguous between the two tables.
                ->where('solutions.name', 'like', "%{$search}%")
                ->orWhereHas('vendor', fn (Builder $v) => $v->where('name', 'like', "%{$search}%"))
                ->orWhereHas('people', fn (Builder $p) => $p->where('name', 'like', "%{$search}%"))))
            ->when($filters['category'] ?? null, fn (Builder $q, $v) => $q->where('category', $v))
            ->when($filters['directorate'] ?? null, fn (Builder $q, $v) => $q->where('directorate', $v))
            ->when($filters['environment'] ?? null, fn (Builder $q, $v) => $q->where('environment', $v))
            ->when($filters['contract'] ?? null, fn (Builder $q, $v) => $q->where('contract_status', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            // Documented THROUGH a caderno — a solution owns no pages of its
            // own since the container swap, so "sem documentação" means every
            // notebook linked to it is empty (or there are none at all).
            ->when($filters['undocumented'] ?? null, fn (Builder $q) => $q->whereDoesntHave('notebooks.documentedPages'));
    }
}
