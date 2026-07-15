<?php

namespace App\Models;

use Database\Factories\FlowspecExampleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Exemplo curado do corpus de flowSpecs Digibee (F8). Selecionado por tags e
 * busca textual (sem RAG) pelo FlowspecContextResolver para compor o prompt
 * do gerador. `connectors` é derivado de `flow_spec` pelo seeder/curadoria —
 * nunca mantido à mão.
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
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Exemplos que possuem QUALQUER uma das tags informadas.
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
