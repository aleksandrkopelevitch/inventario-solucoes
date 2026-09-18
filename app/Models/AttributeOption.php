<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Editable values (at runtime, via the "Manage attributes" area) for the 8
 * Solution attributes, now grouped by `group` (see `App\Enums\AttributeGroup`)
 * — previously these were fixed PHP `enum`s in code. `Criticality` is also
 * used by Diagram, which consumes the same group.
 */
class AttributeOption extends Model
{
    private const CACHE_KEY = 'attribute_options.all';

    protected $fillable = ['group', 'value', 'label', 'icon'];

    /**
     * Where the rebuilt options live for the length of one request.
     *
     * In the CONTAINER, not in a static and not in the cache: the cache store
     * cannot hand Eloquent models back (see `cached()`), and a static would
     * outlive the transaction a test rolls back — options created by one test
     * would still be answering in the next one, which is exactly how this
     * arrived (`DiagramVizGraphTest` passed alone and failed in the suite).
     *
     * Registered SCOPED, not as a plain instance. `forgetScopedInstances()`
     * — which `QueueServiceProvider` runs between jobs — unsets only the keys
     * listed in `$scopedInstances` (Container.php:1719), so a plain
     * `instance()` binding would live as long as the WORKER PROCESS: an admin
     * renaming an attribute clears the cache in the web process, and every
     * queued job would keep answering with the old label until supervisor
     * restarted the worker. Scoped, the memo is one request in production, one
     * job in a worker, and one test in the suite.
     */
    private const MEMO_KEY = 'attribute_options.memo';

    /** Drops the day-long entry AND everything in front of it. */
    public static function forgetCache(): void
    {
        app()->forgetInstance(self::MEMO_KEY);
        Cache::memo()->forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY);
    }

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
        if (! app()->bound(self::MEMO_KEY)) {
            app()->scoped(self::MEMO_KEY, fn () => self::build());
        }

        return app(self::MEMO_KEY);
    }

    /** @return Collection<string, Collection<int, self>> */
    private static function build(): Collection
    {
        // `memo()` on top of the day-long entry, not instead of it: without it
        // this ran one cache READ per call, which on the `database` store is
        // one query per call — and every solution asks 5 times (category,
        // status, criticality, environment, cloud, …). Rendering the catalog
        // or the map therefore cost hundreds of queries to answer from a cache
        // that already held the answer: 545 of them for the 109 blocks of the
        // grouped map, against 0 now. The memo layer lives for the request, so
        // a `Cache::forget()` from the attributes screen is still seen by the
        // next one.
        $raw = Cache::memo()->remember(self::CACHE_KEY, now()->addDay(), fn () => static::query()
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
        // Both layers: `Cache::forget()` alone would leave the request that
        // just saved reading its own stale memo.
        static::saved(fn () => self::forgetCache());
        static::deleted(fn () => self::forgetCache());
    }
}
