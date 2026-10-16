<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives every protected value already in a page the id it is addressed by from
 * now on: `{% secret %}v{% endsecret %}` becomes
 * `{% secret id="k3j9x2ab" %}v{% endsecret %}`.
 *
 * Values used to be addressed by their ORDINAL, and that is what scrambled
 * them: the editor kept the numbers it was handed when the page opened while
 * each of its saves renumbered the page under it (App\Support\Documentation\
 * SecretText says how). `SecretText::mask()` relies on every stored value
 * having an id; `DocumentationPage::booted()` keeps that true for every write
 * from here on, and this is the part it cannot reach — rows nobody saves again.
 *
 * Through the query builder, not the model: assigning an id is not somebody
 * editing the page, so it must move neither `updated_at` nor `updated_by_id`.
 *
 * The regex and the id format are inlined rather than read from SecretText —
 * a migration that has already run everywhere must not change behaviour (or
 * start failing) because that class did. They match it as of this commit.
 */
return new class extends Migration
{
    private const CONSTRUCT = '/\{%\s*secret(?:\s+id="([^"]*)")?\s*%\}(.*?)\{%\s*endsecret\s*%\}/';

    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public function up(): void
    {
        $this->rewrite(function (string $markdown): string {
            preg_match_all('/id="([a-z0-9]{8})"/', $markdown, $existing);
            $taken = array_fill_keys($existing[1], true);

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
        });
    }

    /**
     * Strips the id from EVERY value, not only the ones `up()` assigned: pages
     * saved since then carry ids too. The code this rolls back to knows only
     * `{% secret %}`, so a construct still holding an id would not be
     * recognised as one — and would be rendered as ordinary text, value and
     * all.
     */
    public function down(): void
    {
        $this->rewrite(fn (string $markdown): string => (string) preg_replace(
            '/\{%\s*secret\s+id="[^"]*"\s*%\}/',
            '{% secret %}',
            $markdown,
        ));
    }

    /** @param  callable(string): string  $rewrite */
    private function rewrite(callable $rewrite): void
    {
        DB::table('documentation_pages')
            ->where('documentation', 'like', '%secret%')
            ->select(['id', 'documentation'])
            ->lazyById()
            ->each(function (object $page) use ($rewrite): void {
                $markdown = $rewrite((string) $page->documentation);

                if ($markdown !== $page->documentation) {
                    DB::table('documentation_pages')->where('id', $page->id)->update(['documentation' => $markdown]);
                }
            });
    }
};
