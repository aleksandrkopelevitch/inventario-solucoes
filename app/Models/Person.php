<?php

namespace App\Models;

use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    protected $table = 'people';

    protected $fillable = [
        'name',
        'slug',
        'company_id',
        'job_title',
        'email',
        'phone',
        'photo_path',
        'notes',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function solutions(): BelongsToMany
    {
        return $this->belongsToMany(Solution::class)
            ->withPivot(['role', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * People catalog filters — search by name/company/system and filters by
     * company and role. Reused by People\Index (list), People\ResultsCount
     * (counter) and People\FilterChips, so the three never diverge.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['search'] ?? null, fn (Builder $q, $search) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhereHas('company', fn (Builder $c) => $c->where('name', 'like', "%{$search}%"))
                ->orWhereHas('solutions', fn (Builder $s) => $s->where('name', 'like', "%{$search}%"))))
            ->when($filters['company'] ?? null, fn (Builder $q, $v) => $q->where('company_id', $v))
            // `wherePivot()` only exists on the BelongsToMany relation itself, not on
            // the plain Builder a whereHas() closure receives — calling it here silently
            // resolved to Eloquent's dynamic-where magic method instead (`where('pivot', $v)`,
            // a literal column that doesn't exist), so this filter matched nothing, ever.
            // Reference the pivot table's real column name directly.
            ->when($filters['role'] ?? null, fn (Builder $q, $v) => $q->whereHas('solutions', fn (Builder $s) => $s->where('person_solution.role', $v)));
    }
}
