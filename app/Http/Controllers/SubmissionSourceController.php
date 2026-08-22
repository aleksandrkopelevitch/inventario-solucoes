<?php

namespace App\Http\Controllers;

use App\Actions\Cati\IngestSubmissionSource;
use App\Enums\ContextExtractionState;
use App\Enums\SubmissionSourceKind;
use App\Http\Requests\StoreSubmissionSourceRequest;
use App\Models\Submission;
use App\Models\SubmissionSource;
use App\View\Components\Submissions\Checklist;
use App\View\Components\Submissions\ComposerContext;
use App\View\Components\Submissions\Sources;
use App\View\Components\Submissions\StageStrip;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionSourceController extends Controller
{
    public function store(StoreSubmissionSourceRequest $request, Submission $submission, IngestSubmissionSource $ingest): JsonResponse
    {
        $source = match (true) {
            $request->hasFile('file')       => $ingest->handle($submission, $request->file('file')),
            filled($request->input('text')) => $ingest->handleText(
                $submission,
                (string) $request->validated('text'),
                $request->validated('label'),
            ),
            default => $submission->sources()->create([
                'kind'  => SubmissionSourceKind::Link,
                'label' => $request->validated('label') ?: $request->validated('url'),
                'url'   => $request->validated('url'),
                // A link isn't fetched server-side — doing so would be an SSRF
                // surface for no gain here, since the person can paste the text
                // if they want it read.
                'extraction_state' => ContextExtractionState::Skipped,
                'extraction_note'  => 'Link registrado como referência; o conteúdo não é baixado.',
            ]),
        };

        $submission->load(['sources.media', 'sections', 'solution']);

        return response()->json([
            'type'           => 'success',
            'message'        => $this->confirmation($source),
            'updatableSlots' => $this->slots($submission),
        ]);
    }

    /**
     * What the Toast says.
     *
     * A pasted text gets its own wording because it is the one attach the
     * person did not explicitly ask for: they pasted into a message box and
     * the text vanished from it. "Material anexado." leaves that unexplained,
     * and the client used to cover the gap with a second Toast of its own —
     * two stacked notifications for one gesture.
     */
    private function confirmation(SubmissionSource $source): string
    {
        if ($source->hasSensitiveFindings()) {
            return 'Material anexado — confira o aviso de credencial.';
        }

        return $source->kind === SubmissionSourceKind::Text
            ? 'Texto longo anexado como material — ele vai junto em toda a conversa.'
            : 'Material anexado.';
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

        $submission->load(['sources.media', 'sections', 'solution']);

        return response()->json([
            'type'           => 'success',
            'message'        => 'Material removido.',
            'updatableSlots' => $this->slots($submission),
        ]);
    }

    /**
     * Material shows up in four places: the composer's chips, the Material
     * card, the "Material" stage, and the structural checklist. All four are
     * returned together — three of them sit on a tab that may not even be
     * visible, and a slot the page doesn't have is silently skipped by
     * ajax-slot.js, so sending them all is free.
     *
     * @return list<array{id: string, content: string}>
     */
    private function slots(Submission $submission): array
    {
        return [
            ComposerContext::slot($submission),
            Sources::slot($submission),
            Checklist::slot($submission),
            StageStrip::slot($submission),
        ];
    }
}
