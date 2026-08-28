<?php

namespace App\Models;

use Database\Factories\FlowspecExampleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A curated example from the Digibee flowSpec corpus (F8). Selected by tags
 * and text search (no RAG) by FlowspecContextResolver to compose the
 * Especialista em Integrações' prompt. `connectors` is derived from
 * `flow_spec` by the seeder/curation — never maintained by hand.
 */
class FlowspecExample extends Model
{
    /** @use HasFactory<FlowspecExampleFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'tags',
        'flow_spec',
        'connectors',
        'source',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tags'       => 'array',
            'flow_spec'  => 'array',
            'connectors' => 'array',
            'is_active'  => 'boolean',
            'seeded_at'  => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Examples that have ANY of the given tags.
     *
     * @param  list<string>  $tags
     */
    public function scopeWithAnyTag(Builder $query, array $tags): void
    {
        $query->where(function (Builder $query) use ($tags) {
            foreach ($tags as $tag) {
                $query->orWhereJsonContains('tags', $tag);
            }
        });
    }

    public function scopeSearch(Builder $query, string $term): void
    {
        $query->where(function (Builder $query) use ($term) {
            $query->whereLike('name', "%{$term}%")
                ->orWhereLike('description', "%{$term}%");
        });
    }
}
