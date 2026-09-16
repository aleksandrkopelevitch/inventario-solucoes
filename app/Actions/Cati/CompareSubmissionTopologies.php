<?php

namespace App\Actions\Cati;

use App\Enums\SubmissionDiagramKind;
use App\Exceptions\TopologyCompareFailed;
use App\Models\Submission;
use App\Models\SubmissionDiagram;
use App\Support\Archify\ArchifyRunner;
use App\Support\Archify\ArchitectureIr;
use App\Support\ChainLabeler;
use App\Support\Documentation\ModelJson;

/**
 * What the proposal actually changes, as a Before / Delta / After artifact plus
 * a machine receipt.
 *
 * `SubmissionDiagramKind`'s own docblock says the AS IS and TO BE are drawn
 * rather than uploaded because a picture is "diffable against nothing". This is
 * the diff that sentence was promising: `archify compare architecture` walks
 * two validated specs and reports what was added, removed, changed, moved and
 * rerouted — facts, not an opinion about risk or merge safety.
 *
 * NO MODEL IS INVOLVED. Both sides are drawings somebody made, and the
 * comparison is deterministic: the same two canvases produce the same delta
 * every time, which is the property that makes it worth putting in front of a
 * committee.
 */
class CompareSubmissionTopologies
{
    public function __construct(private readonly ArchifyRunner $archify) {}

    /**
     * @return array{path: string, receipt: array<mixed>} absolute path to the
     *                                                    artifact (caller owns cleanup)
     */
    public function handle(Submission $submission): array
    {
        if (! $this->archify->available()) {
            throw TopologyCompareFailed::rendererUnavailable();
        }

        $asIs = $this->canvas($submission, SubmissionDiagramKind::AsIs);
        $toBe = $this->canvas($submission, SubmissionDiagramKind::ToBe);

        $directory = storage_path('app/archify');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $basePath = tempnam($directory, 'as-is-');
        $headPath = tempnam($directory, 'to-be-');
        // `.json` is not cosmetic: the CLI refuses a receipt path without it.
        $receiptPath = tempnam($directory, 'receipt-') . '.json';
        $outPath = tempnam($directory, 'delta-') . '.html';

        try {
            file_put_contents($basePath, ModelJson::encode($this->spec($asIs, SubmissionDiagramKind::AsIs)));
            file_put_contents($headPath, ModelJson::encode($this->spec($toBe, SubmissionDiagramKind::ToBe)));

            $result = $this->archify->compare($basePath, $headPath, $outPath, $receiptPath);

            if (! $result->ok || ! is_file($outPath)) {
                throw TopologyCompareFailed::rejected($result->problems);
            }

            $receipt = is_file($receiptPath)
                ? (json_decode((string) file_get_contents($receiptPath), true) ?: [])
                : [];
        } finally {
            @unlink($basePath);
            @unlink($headPath);
            @unlink($receiptPath);
        }

        return ['path' => $outPath, 'receipt' => is_array($receipt) ? $receipt : []];
    }

    /**
     * Both canvases have to hold something. An empty AS IS is a legitimate
     * state — `SubmissionDiagramKind::hint()` says so out loud, "em branco
     * significa que nada disso existe ainda" — but it is not something to
     * compare: the delta would be "everything was added", which is what the TO
     * BE already says on its own.
     */
    private function canvas(Submission $submission, SubmissionDiagramKind $kind): SubmissionDiagram
    {
        $diagram = $submission->diagrams()->where('kind', $kind->value)->first();

        // `isFilled()` is the module's own answer to "is there a drawing here",
        // and it is stricter than "has nodes" for a reason: `open()` seeds a
        // root block, so an untouched canvas has one node and would compare as
        // a drawing of one box.
        if ($diagram === null || ! $diagram->isFilled()) {
            throw TopologyCompareFailed::emptyCanvas('a ' . $kind->label());
        }

        return $diagram;
    }

    /** @return array<string, mixed> */
    private function spec(SubmissionDiagram $diagram, SubmissionDiagramKind $kind): array
    {
        $chain = $diagram->chainData();
        $solutions = (new ChainLabeler)->resolveSolutions(collect([$chain]));

        return ArchitectureIr::fromCanvas($chain, $diagram->vizLayout(), $solutions, $kind->label());
    }
}
