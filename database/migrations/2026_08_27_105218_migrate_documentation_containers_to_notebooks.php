<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Moves every existing body of documentation onto a Notebook.
 *
 * Two sources, one destination:
 *
 * - **Every `documentation_groups` row becomes a notebook**, name/slug/
 *   timestamps intact. A group already WAS a caderno in everything but name
 *   (the whole imported GitBook corpus lives in them), so this half is a
 *   rename, not a reshaping.
 * - **Every Solution that owns pages, or that has a public token, gets a
 *   notebook named after it**, linked straight back to that solution through
 *   the new pivot. The token comes across verbatim — a magic link already sent
 *   to somebody must keep resolving, and the token is the only part of that URL
 *   that carries meaning.
 *
 * A solution with neither pages nor a token gets nothing: an empty notebook per
 * undocumented solution would be a hundred empty cadernos nobody asked for
 * (108 solutions, 8 documented, when this was written).
 *
 * Two details that are easy to get wrong:
 *
 * - **Slugs are uniquified against the notebooks already inserted**, not just
 *   against the groups: a group and a solution can legitimately share a name,
 *   and `notebooks.slug` is unique. Same `-2`, `-3` rule the module uses
 *   everywhere else.
 * - **Context documents are repointed, not recreated.** The `context_documents`
 *   media rows hang off `Solution`; the notebook owns that collection now, so
 *   the rows' `model_type`/`model_id` are rewritten in place. Touching the
 *   files on disk isn't needed — Spatie's path is keyed by media id, which
 *   doesn't change.
 *
 * Raw queries throughout, deliberately: a data migration must keep working
 * after the models it reads have moved on (`Solution::pages()` is deleted in
 * this very commit), and Eloquent would bind it to a shape that no longer
 * exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        $slugs = [];

        // --- Standalone groups: a straight rename into notebooks. ---
        foreach (DB::table('documentation_groups')->orderBy('id')->get() as $group) {
            $notebookId = DB::table('notebooks')->insertGetId([
                'name'       => $group->name,
                'slug'       => $this->uniqueSlug($group->slug, $slugs),
                'created_at' => $group->created_at,
                'updated_at' => $group->updated_at,
            ]);

            DB::table('documentation_pages')
                ->where('container_type', 'App\Models\DocumentationGroup')
                ->where('container_id', $group->id)
                ->update(['notebook_id' => $notebookId]);
        }

        // --- Solutions that carry documentation of their own. ---
        $solutions = DB::table('solutions')
            ->where(fn ($q) => $q
                ->whereNotNull('public_token')
                ->orWhereExists(fn ($e) => $e->select(DB::raw(1))->from('documentation_pages')
                    ->whereColumn('documentation_pages.container_id', 'solutions.id')
                    ->where('documentation_pages.container_type', 'App\Models\Solution')))
            ->orderBy('id')
            ->get();

        foreach ($solutions as $solution) {
            $notebookId = DB::table('notebooks')->insertGetId([
                'name'         => $solution->name,
                'slug'         => $this->uniqueSlug($solution->slug, $slugs),
                'public_token' => $solution->public_token,
                'created_at'   => $solution->created_at,
                'updated_at'   => now(),
            ]);

            DB::table('notebook_solution')->insert([
                'notebook_id' => $notebookId,
                'solution_id' => $solution->id,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            DB::table('documentation_pages')
                ->where('container_type', 'App\Models\Solution')
                ->where('container_id', $solution->id)
                ->update(['notebook_id' => $notebookId]);

            // The AI context documents follow their container.
            DB::table('media')
                ->where('collection_name', 'context_documents')
                ->where('model_type', 'App\Models\Solution')
                ->where('model_id', $solution->id)
                ->update(['model_type' => 'App\Models\Notebook', 'model_id' => $notebookId]);
        }
    }

    /**
     * The migration is not reversible in any useful sense: the columns it reads
     * from are dropped two migrations later, so rolling back past this point
     * means restoring a dump. Left explicit rather than silently empty.
     */
    public function down(): void
    {
        DB::table('notebook_solution')->delete();
        DB::table('notebooks')->delete();
        DB::table('documentation_pages')->update(['notebook_id' => null]);
    }

    /**
     * `$taken` accumulates across BOTH loops — a group named "GCP" and a
     * solution named "GCP" would otherwise collide on the unique index, and
     * the second insert would abort the migration halfway through.
     *
     * @param  array<string, true>  $taken
     */
    private function uniqueSlug(?string $base, array &$taken): string
    {
        $base = $base ?: 'caderno';
        $slug = $base;
        $suffix = 1;

        while (isset($taken[$slug])) {
            $slug = $base . '-' . (++$suffix);
        }

        $taken[$slug] = true;

        return $slug;
    }
};
