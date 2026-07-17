<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Editable values (at runtime, via the "Manage attributes" area) for the 8
 * Solution attributes, now grouped by `group` (see `App\Enums\AttributeGroup`)
 * — previously these were fixed PHP `enum`s in code. `Criticality` is also
 * used by Integration, which consumes the same group.
 */
class AttributeOption extends Model
{
    private const CACHE_KEY = 'attribute_options.all';

    protected $fillable = ['group', 'value', 'label', 'icon'];

    /** All options for a group, ordered by label — no extra query (reads from cache). */
    public static function options(string $group): Collection
    {
        return static::cached()->get($group, collect());
    }

    /** Label for a value within a group, or null if the value no longer exists. */
    public static function labelFor(string $group, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return static::options($group)->firstWhere('value', $value)?->label;
    }

    /**
     * Heroicons (outline) icon slug configured for a value, or null if no
     * icon is defined (today only `environment`/`cloud` expose this field in
     * the UI — see `AttributeGroup::supportsIcon()`).
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
