<?php

namespace App\Support;

use Illuminate\Database\Connection;
use PDO;

/**
 * Case- and accent-folding, for comparing text a PERSON typed against text
 * somebody else wrote.
 *
 * Every search in this app is Portuguese, typed in a hurry, usually without
 * accents: "big" has to find "Google BigQuery" and "solucao" has to find
 * "Solução" — and, less obviously, the other way round, since half the catalog
 * is named with accents and half without. Folding both sides to lowercase ASCII
 * is what makes that one comparison instead of four.
 *
 * It became urgent when the dev/production database moved from SQLite to
 * PostgreSQL: SQLite's `LIKE` is case-insensitive for ASCII, Postgres's is not,
 * so every `where(..., 'like', "%$term%")` in the app silently turned
 * case-sensitive on the way over. The test suite could not report it — it runs
 * on SQLite (phpunit.xml), where the old behaviour still holds. Registering
 * this folding as a real SQLite function is what makes a test on SQLite say
 * something true about Postgres.
 *
 * Laravel's own `whereLike($column, $value, caseSensitive: false)` fixes the
 * case half (it emits `ILIKE` on Postgres) and nothing about accents, so it is
 * deliberately not what the `whereFolded` macro is built on: one comparison
 * that answers both questions beats two that each answer half.
 */
final class Fold
{
    /** The name this folding is registered under on a SQLite connection. */
    public const SQL_FUNCTION = 'ak_fold';

    /**
     * Accent folding, ONE character in for one character out, so a character
     * offset in the folded copy is the same offset in the original — that is
     * what lets DocumentationSearchService highlight a match by range. A
     * transliteration that expands (æ → ae) would shift every highlight after
     * it by one. It is also what lets Postgres do the same folding with
     * `translate()`, which is character-for-character by definition.
     *
     * Lowercase keys only: every caller lowercases first, and so does every
     * database function this is compiled into.
     */
    private const ACCENT_MAP = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
    ];

    /** Lowercased and accent-folded, preserving character count. */
    public static function text(string $value): string
    {
        return strtr(mb_strtolower($value), self::ACCENT_MAP);
    }

    /**
     * The character that turns a wildcard back into itself inside a pattern.
     *
     * NOT a backslash, which is what everyone reaches for first: PDO scans the
     * SQL itself to find `?` placeholders, and a backslash inside the single
     * quotes of `escape '\'` leaves its lexer believing the string literal is
     * still open — it then swallows the rest of the query and reports
     * "Invalid parameter number: parameter was not defined" on a statement
     * whose placeholders and bindings match perfectly. `!` has no meaning to
     * either lexer.
     */
    private const ESCAPE = '!';

    /** The `ESCAPE` clause the expression below has to be compared with. */
    public const ESCAPE_SQL = " escape '" . self::ESCAPE . "'";

    /**
     * The same folding, as a term ready to sit inside `%…%`.
     *
     * The wildcards are escaped because the term is data: somebody searching
     * the catalog for "100%" means the character, and `_` is in the name of
     * every ZFL_* page in the corpus. Order matters — the escape character
     * itself goes first, or the `!` this adds in front of a `%` would be
     * escaped by the pass that follows.
     */
    public static function term(string $value): string
    {
        return str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE . self::ESCAPE, self::ESCAPE . '%', self::ESCAPE . '_'],
            self::text(trim($value)),
        );
    }

    /**
     * The folding, in SQL, over a column — the other half of the comparison.
     *
     * Three drivers, because there is no portable way to say this:
     *
     * - **pgsql** — `translate()` over `lower()`. `lower()` is Unicode-aware
     *   even under this database's `C.UTF-8` collation (verified against it),
     *   so the map only needs its lowercase keys. No extension: `unaccent` is
     *   one, and requiring `CREATE EXTENSION` at deploy time to make a search
     *   box work is a bad trade.
     * - **sqlite** — `lower()` is ASCII-only and there is no `translate()`, so
     *   the PHP function above is registered on the connection instead
     *   (`registerOn()`). Identical code on both sides of the comparison, which
     *   is the point: the tests then exercise the real thing.
     * - **anything else** — `lower()` alone, and the note that MySQL's
     *   `utf8mb4_*_ci` collations already compare accent-insensitively, so a
     *   folded term still matches an accented column there. Untested here; this
     *   app runs Postgres and SQLite.
     */
    public static function expression(string $column, Connection $connection): string
    {
        $wrapped = $connection->getQueryGrammar()->wrap($column);

        return match ($connection->getDriverName()) {
            'pgsql'  => sprintf("translate(lower(%s), '%s', '%s')", $wrapped, self::mapKeys(), self::mapValues()),
            'sqlite' => sprintf('%s(%s)', self::SQL_FUNCTION, $wrapped),
            default  => sprintf('lower(%s)', $wrapped),
        };
    }

    /**
     * Teaches a SQLite connection the folding above. A no-op on every other
     * driver, so it is safe to hand every connection the app opens.
     */
    public static function registerOn(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }

        $pdo = $connection->getPdo();

        // The method lives on the sqlite PDO instance rather than on the PDO
        // class, and PHP 8.4 moves it to `Pdo\Sqlite::createFunction`.
        if ($pdo instanceof PDO && method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction(
                self::SQL_FUNCTION,
                static fn (?string $value): ?string => $value === null ? null : self::text($value),
                1,
            );
        }
    }

    /** The accented characters, as one string for `translate()`'s first argument. */
    private static function mapKeys(): string
    {
        return implode('', array_keys(self::ACCENT_MAP));
    }

    /** Their ASCII replacements, in the same order. */
    private static function mapValues(): string
    {
        return implode('', self::ACCENT_MAP);
    }
}
