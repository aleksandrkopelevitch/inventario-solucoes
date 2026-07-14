<?php

namespace App\Models;

use App\Enums\CompanyKind;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'kind',
        'logo_path',
        'website',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'kind' => CompanyKind::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function people(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    public function providedSolutions(): HasMany
    {
        return $this->hasMany(Solution::class, 'vendor_company_id');
    }

    /**
     * Filtros do catálogo de empresas — busca por nome e filtro por tipo.
     * Reaproveitado por Companies\Index (lista), Companies\ResultsCount
     * (contador) e Companies\FilterChips, para os três nunca divergirem.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['search'] ?? null, fn (Builder $q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->when($filters['kind'] ?? null, fn (Builder $q, $v) => $q->where('kind', $v));
    }
}
