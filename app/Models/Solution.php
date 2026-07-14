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

class Solution extends Model
{
    /** @use HasFactory<SolutionFactory> */
    use HasFactory;

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
     * Rótulos dos atributos hoje gerenciados via `AttributeOption` (área
     * "Gerenciar atributos") — as colunas em si guardam apenas o `value`
     * (string), o `label` de exibição vem de uma lookup em memória (cache),
     * nunca uma query por solução (evita N+1 nos cards do catálogo).
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

    /** URL do link público da documentação, ou null se não compartilhada. */
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

    /** Toda integração em que esta solução participa, em qualquer papel. */
    public function integrations(): BelongsToMany
    {
        return $this->belongsToMany(Integration::class, 'integration_solution')
            ->withPivot(['position'])
            ->withTimestamps();
    }

    /** Árvore de páginas de documentação desta Solução (lista plana, ordenada). */
    public function pages(): MorphMany
    {
        return $this->morphMany(DocumentationPage::class, 'container')->orderBy('position');
    }

    /** Só as páginas com conteúdo real — usada pra cobertura ("has_docs") e pro filtro "sem documentação". */
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
     * Filtros do catálogo (F1) — busca por nome/fornecedor/responsável e
     * filtros por categoria, diretoria, hospedagem, contrato, status e "sem
     * documentação". Reaproveitado por Solutions\Index (lista) e
     * Solutions\ResultsCount (contador), para os dois nunca divergirem.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['search'] ?? null, fn (Builder $q, $search) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
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
