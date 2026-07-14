<?php

namespace App\Models;

use Database\Factories\DocumentationGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Aninhamento de páginas de documentação que não pertence a nenhuma Solução
 * — standalone, para docs "soltas" (ex.: um processo transversal). Mesma
 * árvore de páginas (`DocumentationPage`, coluna `documentation`) que uma
 * Solution, via o polimórfico `container`.
 */
class DocumentationGroup extends Model
{
    /** @use HasFactory<DocumentationGroupFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function pages(): MorphMany
    {
        return $this->morphMany(DocumentationPage::class, 'container')->orderBy('position');
    }

    /**
     * Sem FK real pra cascadear (container é polimórfico) — apaga cada
     * página via o próprio model, pra também disparar a limpeza de mídia
     * (Spatie hooka no `deleting` de DocumentationPage).
     */
    protected static function booted(): void
    {
        static::deleting(fn (self $group) => $group->pages->each->delete());
    }
}
