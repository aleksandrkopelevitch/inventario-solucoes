<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * There is one kind of documentation now: `DocumentationPage`.
 *
 * An integration used to carry its own single-page `documentation` column — a
 * second, parallel documentation model with no tree, no container and its own
 * editor route. It is gone, and whatever was written in it becomes a real page
 * in the tree of the solution the diagram starts from, linked back to that
 * diagram (`documentation_pages.diagram_id`). That link is what replaces the
 * old bolted-on Documentação/Diagrama pairing: a page that explains a drawing
 * now says so, and the drawing can be pointed at from as many pages as it
 * actually explains.
 *
 * Written against the query builder, not the models: a data migration has to
 * keep working when `DocumentationPage`'s rules move on.
 *
 * Media is deliberately left where it is. Embedded documentation media is
 * addressed as `/files/{id}` and `MediaController::show()` authorizes it by
 * COLLECTION name (`docs`), never by owner, so a page's Markdown keeps
 * resolving every image it references even while the file row still hangs off
 * the diagram. (In this database the point is moot — the one documented
 * integration embeds no files at all.)
 */
return new class extends Migration
{
    public function up(): void
    {
        $documented = DB::table('diagrams')
            ->whereNotNull('documentation')
            ->where('documentation', '<>', '')
            ->get(['id', 'name', 'documentation', 'source_solution_id']);

        foreach ($documented as $diagram) {
            $solutionId = $diagram->source_solution_id ?? DB::table('diagram_solution')
                ->where('diagram_id', $diagram->id)
                ->orderBy('position')
                ->value('solution_id');

            // A diagram whose chain never referenced a single registered
            // Solution has no tree to land in. Losing the text would be worse
            // than a stray container, so it goes to a group named after it.
            if ($solutionId === null) {
                $containerType = 'App\Models\DocumentationGroup';
                $containerId = DB::table('documentation_groups')->insertGetId([
                    'name'       => $diagram->name,
                    'slug'       => $this->uniqueGroupSlug($diagram->name),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $containerType = 'App\Models\Solution';
                $containerId = $solutionId;
            }

            $pageId = DB::table('documentation_pages')->insertGetId([
                'container_type' => $containerType,
                'container_id'   => $containerId,
                'parent_id'      => null,
                'title'          => $diagram->name,
                'slug'           => $this->uniquePageSlug($containerType, $containerId, $diagram->name),
                'documentation'  => $diagram->documentation,
                'diagram_id'     => $diagram->id,
                'position'       => 1 + (int) DB::table('documentation_pages')
                    ->where('container_type', $containerType)
                    ->where('container_id', $containerId)
                    ->whereNull('parent_id')
                    ->max('position'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // The Assiste IA thread was about this text, so it follows it —
            // its `target` morph pointed at the integration, which no longer
            // exists as a documentation target at all.
            DB::table('documentation_chats')
                ->where('target_type', 'App\Models\Integration')
                ->where('target_id', $diagram->id)
                ->update([
                    'target_type' => 'App\Models\DocumentationPage',
                    'target_id'   => $pageId,
                    'solution_id' => $solutionId,
                    'updated_at'  => now(),
                ]);
        }

        // Any remaining integration-targeted chat has no text left to be about.
        DB::table('documentation_chats')->where('target_type', 'App\Models\Integration')->delete();

        Schema::table('diagrams', function (Blueprint $table) {
            $table->dropColumn('documentation');
        });
    }

    /**
     * Irreversible by design: the column comes back, but which page held which
     * diagram's text is not something this can guess back, and re-splitting one
     * documentation model into two is the change being undone here.
     */
    public function down(): void
    {
        Schema::table('diagrams', function (Blueprint $table) {
            $table->text('documentation')->nullable();
        });
    }

    private function uniquePageSlug(string $containerType, int $containerId, string $title): string
    {
        $base = Str::slug($title) ?: 'pagina';
        $slug = $base;
        $suffix = 1;

        while (DB::table('documentation_pages')
            ->where('container_type', $containerType)
            ->where('container_id', $containerId)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }

    private function uniqueGroupSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'grupo';
        $slug = $base;
        $suffix = 1;

        while (DB::table('documentation_groups')->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
};
