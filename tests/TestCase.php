<?php

namespace Tests;

use App\Support\Digibee\ConnectorDocMap;
use App\Support\Digibee\ConnectorReference;
use App\Support\Digibee\TenantVocabulary;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * The suite refuses to run against anything but an in-memory SQLite
     * database, and this guard exists because the absence of it cost the dev
     * database.
     *
     * `phpunit.xml` sets `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:`, and
     * that is normally enough — Dotenv does not overwrite variables that are
     * already set. A CACHED CONFIG defeats it completely: `config:cache`
     * writes `bootstrap/cache/config.php`, a plain PHP array loaded before any
     * of that applies, so every env-derived value in it wins. On 2026-09-11 a
     * stray cached config pointed the suite at the dev Postgres database and
     * `LazilyRefreshDatabase` migrated it fresh — 2618 rows, restored from a
     * three-week-old sqlite backup.
     *
     * Failing loudly here is the cheap half of that lesson. The expensive half
     * is that nothing else in the stack complains: the tests simply ran, most
     * of them failed for unrelated-looking reasons (419 where 403 was
     * expected), and the damage was silent.
     */
    protected function refuseNonTestDatabase(): void
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection === 'sqlite' && in_array($database, [':memory:', ''], true)) {
            return;
        }

        throw new RuntimeException(
            "The test suite is pointed at [{$connection}] database [{$database}], not sqlite :memory:. "
            . 'Refusing to run: a refreshing database test would wipe it. This almost always means a stale '
            . 'bootstrap/cache/config.php — run `php artisan config:clear`.'
        );
    }

    /**
     * The Digibee reference artifacts are read through STATIC memos — they are
     * files that never change during a request, so re-reading them per prompt
     * would be waste. A static survives the per-test application, though, so a
     * test that points `services.digibee.cards_path` at a fixture would
     * otherwise leave that fixture answering for every test after it, in
     * whatever order the suite happens to run. Cheap to drop, impossible to
     * debug once it bites.
     */
    /**
     * The guard runs HERE, not in `setUp()`, and the difference is the whole
     * point: `setUpTraits()` is where RefreshDatabase / LazilyRefreshDatabase
     * install themselves, so checking after `parent::setUp()` means checking
     * after the database may already have been migrated fresh. It survived the
     * first time only because the lazy variant defers until the first query —
     * which is luck, not a guard.
     */
    protected function setUpTraits(): array
    {
        $this->refuseNonTestDatabase();

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        ConnectorDocMap::flush();
        ConnectorReference::flush();
        TenantVocabulary::flush();
    }
}
