<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Valores editáveis (em runtime, via a área "Gerenciar atributos") dos 8
 * atributos de Solução hoje agrupados por `group` (ver `App\Enums\AttributeGroup`)
 * — antes eram `enum`s PHP fixos em código. `Criticality` também é usado por
 * Integration, que consome o mesmo grupo.
 */
class AttributeOption extends Model
{
    private const CACHE_KEY = 'attribute_options.all';

    protected $fillable = ['group', 'value', 'label', 'icon'];

    /** Todas as opções de um grupo, ordenadas por rótulo — sem query extra (lê do cache). */
    public static function options(string $group): Collection
    {
        return static::cached()->get($group, collect());
    }

    /** Rótulo de um valor dentro de um grupo, ou null se o valor não existir mais. */
    public static function labelFor(string $group, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return static::options($group)->firstWhere('value', $value)?->label;
    }

    /**
     * Slug do ícone heroicons (outline) configurado para um valor, ou null se
     * não houver ícone definido (hoje só `environment`/`cloud` expõem esse
     * campo na UI — ver `AttributeGroup::supportsIcon()`).
     */
    public static function iconFor(string $group, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return static::options($group)->firstWhere('value', $value)?->icon;
    }

    /**
     * The `database` cache store only unserializes an explicit allow-list of
     * classes (`config('cache.stores.database.serializable_classes')`, unset
     * here) — caching an Eloquent Collection/Model directly comes back as
     * `__PHP_Incomplete_Class`. So only plain arrays touch the cache; models
     * are rebuilt in memory from that array on every read.
     *
     * @return Collection<string, Collection<int, self>>
     */
    private static function cached(): Collection
    {
        $raw = Cache::remember(self::CACHE_KEY, now()->addDay(), fn () => static::query()
            ->orderBy('label')
            ->get(['id', 'group', 'value', 'label', 'icon'])
            ->groupBy('group')
            ->map(fn (Collection $options) => $options->map->only(['id', 'group', 'value', 'label', 'icon'])->all())
            ->all());

        return collect($raw)->map(fn (array $options) => collect($options)->map(function (array $attrs) {
            $option = new self(['group' => $attrs['group'], 'value' => $attrs['value'], 'label' => $attrs['label'], 'icon' => $attrs['icon']]);
            $option->id = $attrs['id'];
            $option->exists = true;

            return $option;
        }));
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }
}
