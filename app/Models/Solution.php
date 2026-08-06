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
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Solution extends Model implements HasMedia
{
    /** @use HasFactory<SolutionFactory> */
    use HasFactory, InteractsWithMedia;

    /**
     * Context documents (PDF/image/text) attached to the Solution and reused
     * by the Documentation Assistant chat — they feed the LLM generation
     * (App\Services\Documentation\ContextDocumentResolver). Separate from
     * the `docs` collection (media embedded in the documentation): these
     * files never appear in the Markdown, they only go into the model's
     * prompt.
     */
    public const CONTEXT_COLLECTION = 'context_documents';

    protected $fillable = [
        'name',
        'slug',
        'public_token',
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

    public function registerMediaCollections(): void
    {
        // Context documents for "Assiste IA" — served by
        // SolutionContextDocumentController (the `files.show`/MediaController
        // route only serves the `docs` collection).
        $this->addMediaCollection(self::CONTEXT_COLLECTION);
    }

    /** URL of the documentation's public link, or null if not shared. */
    public function publicDocsUrl(): ?string
    {
        return $this->public_token
            ? route('public.docs.solution', $this->public_token)
            : null;
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

    public function integrationsAsSource(): HasMany
    {
        return $this->hasMany(Integration::class, 'source_solution_id');
    }

    public function integrationsAsTarget(): HasMany
    {
        return $this->hasMany(Integration::class, 'target_solution_id');
    }

    /** Every integration this solution participates in, in any role. */
    public function integrations(): BelongsToMany
    {
        return $this->belongsToMany(Integration::class, 'integration_solution')
            ->withPivot(['position'])
            ->withTimestamps();
    }

    /** This Solution's documentation page tree (flat, ordered list). */
    public function pages(): MorphMany
    {
        return $this->morphMany(DocumentationPage::class, 'container')->orderBy('position');
    }

    /** Only pages with actual content — used for coverage ("has_docs") and the "no documentation" filter. */
    public function documentedPages(): MorphMany
    {
        return $this->pages()->whereNotNull('documentation')->where('documentation', '<>', '');
    }

    public function scopeWithIntegrationCounts(Builder $query): void
    {
        $query->withCount([
            'integrationsAsSource as active_out' => fn (Builder $q) => $q->where('status', 'active'),
            'integrationsAsTarget as active_in'  => fn (Builder $q) => $q->where('status', 'active'),
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
            ->when($filters['undocumented'] ?? null, fn (Builder $q) => $q->whereDoesntHave('documentedPages'));
    }
}
