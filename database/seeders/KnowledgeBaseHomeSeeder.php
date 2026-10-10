<?php

namespace Database\Seeders;

use App\Contracts\Documentable;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Services\DocumentationPageService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the knowledge base's landing (`/docs`) — the home caderno, its one
 * page and the animated pictures that page explains things with.
 *
 * ONE-OFF, and that is the point of it being a seeder rather than a migration
 * or part of every deploy. From the moment it exists the landing belongs to
 * whoever edits it in Cadernos, so this never touches a home that is already
 * there: re-running it is a no-op, not a reset. Run it once per environment —
 * `envoy run kb-home` on the droplet.
 *
 * Not a migration because a migration would also run under the test suite,
 * where every test would start with a published caderno it never asked for.
 *
 * The source lives in `database/data/knowledge-base-home/`: `home.md` in the
 * editor's own Markdown dialect, plus one SVG per picture. A picture is written
 * as `{{figure:file.svg|legenda}}` and becomes an ordinary image block — media
 * in the page's `docs` collection, referenced as `/files/{id}` — so an editor
 * can move, replace or delete it like any image they uploaded themselves.
 */
class KnowledgeBaseHomeSeeder extends Seeder
{
    private const SOURCE = 'data/knowledge-base-home';

    private const NAME = 'Base de conhecimento';

    private const PAGE_TITLE = 'Boas-vindas à base de conhecimento';

    public function run(DocumentationPageService $pages): void
    {
        if (Notebook::query()->where('is_home', true)->exists()) {
            $this->command?->info('A home caderno already exists — left untouched.');

            return;
        }

        $notebook = Notebook::create(['name' => self::NAME, 'slug' => $this->uniqueSlug()]);

        // Neither column is fillable (see Notebook::casts()): which caderno is
        // the landing, and whether it is published, are not mass-assignable.
        $notebook->forceFill(['is_home' => true, 'published_at' => now()])->save();

        $page = $pages->create($notebook, self::PAGE_TITLE);
        $page->update(['documentation' => $this->markdown($page)]);

        $this->command?->info("Created the home caderno \"{$notebook->name}\" ({$notebook->slug}), published at /docs.");
    }

    /** `home.md` with every `{{figure:…}}` uploaded and written as an image block. */
    private function markdown(DocumentationPage $page): string
    {
        $directory = database_path(self::SOURCE);

        return preg_replace_callback(
            '/\{\{figure:([\w.-]+)\|(.+?)\}\}/',
            function (array $m) use ($page, $directory): string {
                $media = $page->addMedia($directory . '/' . $m[1])
                    ->preservingOriginal()
                    ->toMediaCollection(Documentable::DOCS_COLLECTION);

                // The exact shape docs-markdown.js serializes an image block
                // to, so the editor opens it as one rather than as raw HTML.
                $caption = e($m[2]);

                return '<figure><img src="/files/' . $media->id . '" alt="' . $caption . '">'
                    . '<figcaption>' . $caption . '</figcaption></figure>';
            },
            (string) file_get_contents($directory . '/home.md'),
        );
    }

    private function uniqueSlug(): string
    {
        $base = Str::slug(self::NAME);
        $slug = $base;
        $suffix = 1;

        while (Notebook::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
}
