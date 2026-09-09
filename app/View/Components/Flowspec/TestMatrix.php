<?php

namespace App\View\Components\Flowspec;

use App\Actions\Flowspec\BuildPipelineTestMatrix;
use App\Models\FlowspecMessage;
use App\Support\Digibee\Testing\PipelineTestSuite;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\View\Component;

/**
 * The synthetic test battery for a generated flowSpec, on the message that
 * produced it.
 *
 * `BuildPipelineTestMatrix` has existed since block E with no reader: it
 * derives a median of 11 cases per document and nothing in the app showed one,
 * so the coverage debt it reports so carefully ("preencha um CPF real") was
 * visible only from PHP. The blocked cases are the point — a case somebody
 * still owes is only useful if somebody reads it.
 *
 * **Only a VALIDATED document gets a battery.** A document with pending errors
 * still builds one (the matrix is deliberately tolerant), but its cases
 * describe a flow the validator has already refused — offering them would be
 * offering a test plan for something nobody can deploy.
 *
 * The pipeline NAME is the conversation's title, slugged, and it is worth
 * knowing that it is a placeholder: nothing has been ingested yet, so the
 * suite's own name is the only place it shows, and the runner's URL is not
 * rendered at all rather than being built from a guess.
 */
class TestMatrix extends Component
{
    public function __construct(
        public FlowspecMessage $message,
        public string $pipelineName,
    ) {}

    public function shouldRender(): bool
    {
        return is_array($this->message->flow_spec)
            && ($this->message->meta['validated'] ?? false) === true;
    }

    public function render(): View
    {
        $suite = app(BuildPipelineTestMatrix::class)->handle(
            (array) $this->message->flow_spec,
            Str::slug($this->pipelineName) ?: 'pipeline',
        );

        return view('components.flowspec.test-matrix', [
            'message'  => $this->message,
            'suite'    => $suite,
            'coverage' => $suite->coverage(),
            'json'     => $this->json($suite),
        ]);
    }

    /**
     * The §3.4 `testSuite` document — the artifact that travels between the
     * generator, the runner and the self-healing loop, which is why it is
     * offered whole rather than as a prettified reading of itself.
     */
    private function json(PipelineTestSuite $suite): string
    {
        return (string) json_encode(
            $suite->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
