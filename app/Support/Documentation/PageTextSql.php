<?php

namespace App\Support\Documentation;

use Illuminate\Database\Connection;
use PDO;

/**
 * The two questions a tree walk asks of `documentation_pages.documentation`
 * WITHOUT reading it: whether there is any, and what its hash is.
 *
 * The column is the whole corpus — ~5 MB over the 630-page Digibee caderno —
 * and every screen that draws a caderno's tree used to hydrate all of it to
 * answer one of these two questions per page: the rail ("is there anything to
 * read here?") and the search index's key (an md5 proving the cache fresh).
 * Both are answered in SQL now, so a tree walk transfers a few bytes per page.
 *
 * Two drivers, two spellings each. Postgres has `md5()` built in; SQLite — the
 * test suite — has no hash function at all, so one is registered on the
 * connection (`registerOn()`), the same way `App\Support\Fold` teaches it the
 * accent folding. The PHP side of each is spelled identically to what the
 * model computes from a hydrated text (`DocumentationPage::hasContent()`,
 * `md5()`), which is what keeps a light row and a full row giving one answer.
 */
final class PageTextSql
{
    public const MD5_FUNCTION = 'ak_md5';

    /** The alias `md5Expression()` is selected under. */
    public const MD5_ALIAS = 'documentation_md5';

    /** The alias `hasContentExpression()` is selected under. */
    public const HAS_CONTENT_ALIAS = 'has_content';

    /**
     * `md5(text)` — of the EMPTY string for a null column, so a page with no
     * text and a page whose text is '' hash the same, as `md5((string) null)`
     * does in PHP.
     *
     * Not free: the database has to read every page to hash it (12 ms over
     * the 2 MB caderno, against 15 ms to hydrate it whole), so this is
     * selected only where the hash is actually wanted — the search index's key
     * — and never on the tree the rail draws.
     */
    public static function md5Expression(Connection $connection, string $column = 'documentation'): string
    {
        $wrapped = $connection->getQueryGrammar()->wrap($column);

        return match ($connection->getDriverName()) {
            'sqlite' => sprintf("%s(coalesce(%s, ''))", self::MD5_FUNCTION, $wrapped),
            default  => sprintf("md5(coalesce(%s, ''))", $wrapped),
        };
    }

    /**
     * Whether the column holds anything at all — the same expression
     * `DocumentationCoverageService` counts documented pages with.
     *
     * Deliberately NOT a trim: measured on the 133-page caderno (2 MB) against
     * localhost Postgres, `coalesce(col, '') <> ''` costs nothing over the
     * bare columns (3.7 ms) while `length(btrim(col, …)) > 0` costs 21 ms —
     * more than hydrating the whole text (15 ms), since the function has to
     * detoast and scan every page. The one page this answers differently from
     * PHP's `trim()` is a page made of whitespace only, which the editor never
     * writes (an emptied page saves as '').
     */
    public static function hasContentExpression(Connection $connection, string $column = 'documentation'): string
    {
        $wrapped = $connection->getQueryGrammar()->wrap($column);

        return sprintf("(coalesce(%s, '') <> '')", $wrapped);
    }

    /** Teaches a SQLite connection `md5()`. A no-op on every other driver. */
    public static function registerOn(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }

        $pdo = $connection->getPdo();

        if ($pdo instanceof PDO && method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction(
                self::MD5_FUNCTION,
                static fn (?string $value): string => md5((string) $value),
                1,
            );
        }
    }
}
