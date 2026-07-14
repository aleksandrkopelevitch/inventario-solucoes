<?php

use App\Models\DocumentationPage;
use App\Models\Solution;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Cada Solution com `documentation` preenchida vira sua primeira página
     * ("Visão geral") na nova tabela `documentation_pages` — a mídia embutida
     * (coleção `docs`, referenciada por /files/{id} no Markdown) é reatribuída
     * da Solution pra essa página, senão os links quebrariam.
     */
    public function up(): void
    {
        Solution::query()
            ->whereNotNull('documentation')
            ->where('documentation', '<>', '')
            ->each(function (Solution $solution) {
                $pageId = DB::table('documentation_pages')->insertGetId([
                    'container_type' => Solution::class,
                    'container_id'   => $solution->id,
                    'title'          => 'Visão geral',
                    'slug'           => 'visao-geral',
                    'documentation'  => $solution->documentation,
                    'position'       => 0,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);

                DB::table('media')
                    ->where('model_type', Solution::class)
                    ->where('model_id', $solution->id)
                    ->where('collection_name', 'docs')
                    ->update([
                        'model_type' => DocumentationPage::class,
                        'model_id'   => $pageId,
                    ]);
            });
    }

    public function down(): void
    {
        DocumentationPage::query()
            ->where('container_type', Solution::class)
            ->each(function (DocumentationPage $page) {
                DB::table('media')
                    ->where('model_type', DocumentationPage::class)
                    ->where('model_id', $page->id)
                    ->where('collection_name', 'docs')
                    ->update([
                        'model_type' => Solution::class,
                        'model_id'   => $page->container_id,
                    ]);

                DB::table('solutions')
                    ->where('id', $page->container_id)
                    ->update(['documentation' => $page->documentation]);

                $page->delete();
            });
    }
};
