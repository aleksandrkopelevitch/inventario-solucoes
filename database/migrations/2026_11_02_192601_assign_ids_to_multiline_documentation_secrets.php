<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives an id to every protected value that spans more than one line.
 *
 * Until 2026-11-02 `SecretText::CONSTRUCT` stopped at a newline, so a
 * multi-line value matched nothing: it was never protected, and never given an
 * id either (`2026_10_16_175559_assign_ids_to_documentation_secrets` and
 * `DocumentationPage::booted()` both used the old pattern). The new pattern
 * protects it — and `mask()` relies on every stored value having an id. A value
 * without one would be handed to the editor under a fresh id that no save can
 * resolve, so the page's first save would replace it with a dead lock. This
 * assigns them before anybody opens such a page.
 *
 * Through the query builder, for the reason the earlier migration gives:
 * assigning an id is not somebody editing the page. The pattern is inlined and
 * matches `SecretText::CONSTRUCT` as of this commit; values that already have an
 * id are left alone, so running it over pages without multi-line values is a
 * no-op.
 */
return new class extends Migration
{
    private const CONSTRUCT = '/\{%\s*secret(?:\s+id="([^"]*)")?\s*%\}((?:(?!\{%\s*(?:end)?secret\b)[\s\S])*?)\{%\s*endsecret\s*%\}/';

    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public function up(): void
    {
        DB::table('documentation_pages')
            ->where('documentation', 'like', '%secret%')
            ->select(['id', 'documentation'])
            ->lazyById()
            ->each(function (object $page): void {
                $markdown = $this->withIds((string) $page->documentation);

                if ($markdown !== $page->documentation) {
                    DB::table('documentation_pages')->where('id', $page->id)->update(['documentation' => $markdown]);
                }
            });
    }

    /**
     * Nothing to undo: the code a rollback returns to cannot see a multi-line
     * construct at all, so an id on one changes nothing it does.
     */
    public function down(): void {}

    private function withIds(string $markdown): string
    {
        preg_match_all('/id="([a-z0-9]{8})"|\[\[SECRET-([a-z0-9]{8})\]\]/', $markdown, $existing);
        $taken = array_fill_keys(array_filter([...$existing[1], ...$existing[2]]), true);

        return (string) preg_replace_callback(
            self::CONSTRUCT,
            function (array $m) use (&$taken): string {
                if (preg_match('/^[a-z0-9]{8}$/', $m[1]) === 1) {
                    return $m[0];
                }

                do {
                    $id = '';

                    for ($i = 0; $i < 8; $i++) {
                        $id .= self::ALPHABET[random_int(0, 35)];
                    }
                } while (isset($taken[$id]));

                $taken[$id] = true;

                return '{% secret id="' . $id . '" %}' . $m[2] . '{% endsecret %}';
            },
            $markdown,
        );
    }
};
