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
     * Company catalog filters — search by name and filter by type.
     * Reused by Companies\Index (list), Companies\ResultsCount (counter) and
     * Companies\FilterChips, so the three never diverge.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['search'] ?? null, fn (Builder $q, $search) => $q->whereFolded('name', $search))
            ->when($filters['kind'] ?? null, fn (Builder $q, $v) => $q->where('kind', $v));
    }
}
