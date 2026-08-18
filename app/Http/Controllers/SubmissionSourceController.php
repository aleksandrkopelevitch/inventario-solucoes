<?php

namespace App\Http\Controllers;

use App\Actions\Cati\IngestSubmissionSource;
use App\Enums\SubmissionSourceExtraction;
use App\Enums\SubmissionSourceKind;
use App\Http\Requests\StoreSubmissionSourceRequest;
use App\Models\Submission;
use App\Models\SubmissionSource;
use App\View\Components\Submissions\Checklist;
use App\View\Components\Submissions\Sources;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionSourceController extends Controller
{
    public function store(StoreSubmissionSourceRequest $request, Submission $submission, IngestSubmissionSource $ingest): JsonResponse
    {
        $source = $request->hasFile('file')
            ? $ingest->handle($submission, $request->file('file'))
            : $submission->sources()->create([
                'kind'  => SubmissionSourceKind::Link,
                'label' => $request->validated('label') ?: $request->validated('url'),
                'url'   => $request->validated('url'),
                // A link isn't fetched server-side — doing so would be an SSRF
                // surface for no gain here, since the person can paste the text
                // if they want it read.
                'extraction_state' => SubmissionSourceExtraction::Skipped,
                'extraction_note'  => 'Link registrado como referência; o conteúdo não é baixado.',
            ]);

        $submission->load(['sources', 'sections', 'solution']);

        return response()->json([
            'type'    => 'success',
            'message' => $source->hasSensitiveFindings()
                ? 'Material anexado — confira o aviso de credencial.'
                : 'Material anexado.',
            'updatableSlots' => [Sources::slot($submission), Checklist::slot($submission)],
        ]);
    }

    /** Serves an uploaded file. Not `files.show`, which only serves the `docs` collection. */
    public function show(Submission $submission, SubmissionSource $source): StreamedResponse
    {
        $this->authorize('view', $submission);

        abort_unless($source->media !== null, 404);

        return $source->media->toResponse(request());
    }

    public function destroy(Submission $submission, SubmissionSource $source): JsonResponse
    {
        $this->authorize('update', $submission);

        $source->media?->delete();
        $source->delete();

        $submission->load(['sources', 'sections', 'solution']);

        return response()->json([
            'type'           => 'success',
            'message'        => 'Material removido.',
            'updatableSlots' => [Sources::slot($submission), Checklist::slot($submission)],
        ]);
    }
}
