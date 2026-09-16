<?php

namespace App\Http\Controllers;

use App\Actions\Cati\CompareSubmissionTopologies;
use App\Models\Submission;
use App\View\Components\Submissions\Diagrams;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * "O que muda" — the submission's AS IS compared with its TO BE.
 *
 * Deterministic and model-free: two drawings in, one Before/Delta/After
 * artifact and one machine receipt out. That is what makes it worth putting in
 * front of a committee — the same two canvases produce the same answer every
 * time, and the answer is a list of facts (added, removed, changed, moved,
 * rerouted) rather than an opinion about risk.
 */
class SubmissionTopologyDeltaController extends Controller
{
    public function __construct(private readonly CompareSubmissionTopologies $compare) {}

    public function store(Submission $submission): JsonResponse
    {
        $this->authorize('update', $submission);

        ['path' => $path, 'receipt' => $receipt] = $this->compare->handle($submission);

        try {
            $submission->addMedia($path)
                ->usingName('AS IS × TO BE')
                ->usingFileName('as-is-x-to-be.html')
                ->withCustomProperties([
                    'generated_at' => now()->toIso8601String(),
                    // The counts, so the tab can state what changed without
                    // opening (or parsing) the artifact.
                    'counts' => $this->counts($receipt),
                ])
                ->toMediaCollection(Submission::TOPOLOGY_DELTA_COLLECTION);
        } finally {
            @unlink($path);
        }

        return response()->json([
            'type'           => 'success',
            'message'        => 'Comparação gerada.',
            'updatableSlots' => [Diagrams::slot($submission->fresh())],
        ]);
    }

    /**
     * Same sandboxing as a page artifact, and for the same reason: a full HTML
     * document with its own scripts must not run on this app's origin. See
     * `NotebookPageArtifactController::show()`.
     */
    public function show(Submission $submission): BinaryFileResponse
    {
        $this->authorize('view', $submission);

        $media = $submission->getFirstMedia(Submission::TOPOLOGY_DELTA_COLLECTION);

        abort_if($media === null, 404);

        return response()->file($media->getPath(), [
            'Content-Type'            => 'text/html; charset=utf-8',
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => implode('; ', [
                "default-src 'none'",
                "style-src 'unsafe-inline'",
                "script-src 'unsafe-inline'",
                'img-src data: blob:',
                'font-src data:',
                "connect-src 'none'",
                'sandbox allow-scripts allow-downloads',
            ]),
        ]);
    }

    /**
     * The receipt's own `summary`, narrowed to the two families a committee
     * reads: blocks and links. Read defensively — every count optional,
     * anything unrecognised ignored — because the shape belongs to a vendored
     * renderer, and a renamed key must not 500 a committee screen.
     *
     * @param  array<mixed>  $receipt
     * @return array<string, array<string, int>>
     */
    private function counts(array $receipt): array
    {
        $summary = $receipt['summary'] ?? [];

        if (! is_array($summary)) {
            return [];
        }

        $counts = [];

        foreach (['components' => ['added', 'removed', 'changed', 'moved'], 'connections' => ['added', 'removed', 'changed', 'rerouted']] as $family => $kinds) {
            foreach ($kinds as $kind) {
                $value = $summary[$family][$kind] ?? null;

                if (is_int($value) && $value > 0) {
                    $counts[$family][$kind] = $value;
                }
            }
        }

        return $counts;
    }
}
