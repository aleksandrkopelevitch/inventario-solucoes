<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

return new class extends Migration
{
    /**
     * The committee's AS IS / TO BE stop being drawn on an in-app canvas and
     * become uploads, like the C4 slots
     * (docs-and-specs/solution-relationships-from-uml.md §8.2, phase 4b).
     *
     * - Each drawing's rendered PNG (`diagram` collection) MOVES into the
     *   upload collection (`submission_diagram`) — the same row and the same
     *   file, since the path is the media id and not the collection. Where an
     *   upload is already there it was the person's explicit choice: it stays,
     *   and the PNG is deleted.
     * - The pictures pasted onto a canvas (`docs` collection) meant something
     *   only inside it, and are deleted.
     *
     * Every delete goes through the Media MODEL, so Spatie removes the files
     * with the rows. A canvas somebody drew on but never saved has no PNG, and
     * that drawing cannot be kept: such slots are named in the log.
     */
    public function up(): void
    {
        $model = 'App\\Models\\SubmissionDiagram';

        $uploaded = Media::query()
            ->where('model_type', $model)
            ->where('collection_name', 'submission_diagram')
            ->pluck('model_id')
            ->all();

        Media::query()
            ->where('model_type', $model)
            ->where('collection_name', 'diagram')
            ->lazyById()
            ->each(function (Media $media) use ($uploaded): void {
                if (in_array($media->model_id, $uploaded)) {
                    $media->delete();

                    return;
                }

                $media->collection_name = 'submission_diagram';
                $media->save();
            });

        Media::query()
            ->where('model_type', $model)
            ->where('collection_name', 'docs')
            ->lazyById()
            ->each(fn (Media $media) => $media->delete());

        $this->reportLostDrawings($model);
    }

    /** The PNGs are uploads now and the pasted pictures are gone; nothing to put back. */
    public function down(): void {}

    /** AS IS / TO BE slots that held a drawing (more than the seeded root) and no picture. */
    private function reportLostDrawings(string $model): void
    {
        // Gone once the next migration has run: a re-run (the test suite) has
        // no drawings left to lose.
        if (! Schema::hasColumn('submission_diagrams', 'chain')) {
            return;
        }

        $withPicture = Media::query()
            ->where('model_type', $model)
            ->where('collection_name', 'submission_diagram')
            ->pluck('model_id')
            ->all();

        $lost = DB::table('submission_diagrams')
            ->join('submissions', 'submissions.id', '=', 'submission_diagrams.submission_id')
            ->whereIn('submission_diagrams.kind', ['as_is', 'to_be'])
            ->whereNotIn('submission_diagrams.id', $withPicture)
            ->get(['submission_diagrams.id', 'submission_diagrams.kind', 'submission_diagrams.chain', 'submissions.slug'])
            ->filter(fn (object $row) => count(json_decode((string) $row->chain, true)['nodes'] ?? []) > 1)
            ->map(fn (object $row) => "{$row->slug} ({$row->kind})")
            ->values();

        if ($lost->isNotEmpty()) {
            Log::warning('CATI canvas removal: drawings with no saved picture were dropped', ['slots' => $lost->all()]);
        }
    }
};
